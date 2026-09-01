<?php

namespace App\Services;

use App\Enums\AppointmentOutcome;
use App\Enums\AppointmentStatus;
use App\Enums\DemoMode;
use App\Enums\DemoNextAction;
use App\Enums\DemoOutcome;
use App\Enums\DemoStatus;
use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\ProposalStage;
use App\Models\Appointment;
use App\Models\Demo;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Proposal;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Phase 2: the centralized outcome-driven transition engine — Reschedule
 * (App\Services\RescheduleService) is a different, narrower concern (the
 * SAME not-yet-conducted activity replaced because its schedule changed).
 * This service instead handles "an activity was actually conducted/
 * decided, and its recorded outcome implies the next business action":
 * Appointment outcome -> (Follow-Up | Lead | new Appointment | Demo |
 * Proposal | nothing), Lead -> Demo, Demo outcome -> (another Demo |
 * Proposal | back to Lead's Requirement Collection | Demo Follow-Up |
 * nothing).
 *
 * CRITICAL DISTINCTION (repeat activity vs reschedule — see the Phase 2
 * plan's explicit correction): when a completed activity's outcome
 * implies creating another one of the same type ("Another Appointment
 * Required", "Schedule Another Demo"), the ORIGINAL becomes `Completed`
 * (never `Rescheduled` — it actually happened and produced a real
 * business outcome) and the new one is linked via `origin_type`/
 * `origin_id` (lineage — "created because of"), never
 * `rescheduled_from_id` (which means "replaces the same UNCONDUCTED
 * activity because its schedule changed"). RescheduleService is never
 * called from within this service.
 *
 * Every method here is DB::transaction()-wrapped: outcome, next_action,
 * status, and the downstream record are all set/created together, or
 * none of them are — never "save outcome first, transition later".
 */
class WorkflowTransitionService
{
    /**
     * @param  array<string, mixed>  $data  destination-specific fields the chosen outcome requires
     *     (e.g. 'follow_up_at'/'reason' for FollowUpRequired, 'lead_id' for DemoRequired/ProposalRequired),
     *     plus 'outcome_notes' — required by Appointment's own model guard whenever status reaches
     *     Completed (mirrors the legacy stage-driven "Succeeded/Not Succeeded requires Outcome Notes" rule,
     *     migrated in Phase 3 to key off normalized status instead).
     */
    public function transitionAppointmentOutcome(Appointment $appointment, AppointmentOutcome $outcome, array $data): void
    {
        DB::transaction(function () use ($appointment, $outcome, $data) {
            $locked = Appointment::query()->whereKey($appointment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status?->value !== AppointmentStatus::Scheduled->value) {
                throw new LogicException(
                    "Appointment #{$locked->getKey()} cannot be transitioned: its status is ".
                    ($locked->status?->value ?? 'unset').', not scheduled.'
                );
            }

            $locked->forceFill([
                'outcome' => $outcome->value,
                'status' => AppointmentStatus::Completed->value,
                'outcome_notes' => $data['outcome_notes'] ?? $locked->outcome_notes,
            ])->save();

            $downstream = match ($outcome) {
                AppointmentOutcome::FollowUpRequired => $this->createFollowUpFromOrigin($locked, 'appointment', $data),
                AppointmentOutcome::RequirementIdentified => $this->createLeadFromAppointment($locked),
                AppointmentOutcome::AnotherAppointmentRequired => $this->createRepeatAppointment($locked, $data),
                AppointmentOutcome::DemoRequired => $this->transitionToDemo($this->requireLeadForAppointment($locked, $data), $locked, 'appointment', $data),
                AppointmentOutcome::ProposalRequired => $this->createProposalFromLead($this->requireLeadForAppointment($locked, $data)),
                AppointmentOutcome::NoCurrentRequirement => null,
            };

            AuditLogger::record(
                entityType: 'Appointment',
                entityId: $locked->getKey(),
                action: 'appointment_outcome_recorded',
                organizationId: $locked->organization_id,
                before: ['status' => AppointmentStatus::Scheduled->value, 'outcome' => null],
                after: [
                    'status' => AppointmentStatus::Completed->value,
                    'outcome' => $outcome->value,
                    'downstream_type' => $downstream ? class_basename($downstream) : null,
                    'downstream_id' => $downstream?->getKey(),
                ],
            );
        });
    }

    private function createFollowUpFromOrigin(Appointment|Demo $origin, string $originAlias, array $data): FollowUp
    {
        $followUp = new FollowUp([
            'prospect_id' => $origin->prospect_id,
            'user_id' => $origin->assigned_to,
            'follow_up_at' => $data['follow_up_at'] ?? null,
            'reason' => $data['reason'] ?? 'Follow-Up Required',
            'status' => 'pending',
        ]);
        $followUp->forceFill(['origin_type' => $originAlias, 'origin_id' => $origin->getKey()]);
        $followUp->save();

        return $followUp;
    }

    private function createLeadFromAppointment(Appointment $appointment): Lead
    {
        return Lead::create([
            'prospect_id' => $appointment->prospect_id,
            'assigned_to' => $appointment->assigned_to,
            'created_by' => $appointment->created_by,
            'stage' => LeadStage::RequirementCollection->value,
            'status' => LeadStatus::RequirementCollection->value,
            'temperature' => LeadTemperature::Warm->value,
        ]);
    }

    private function createRepeatAppointment(Appointment $completed, array $data): Appointment
    {
        $new = new Appointment(array_merge(
            $completed->replacementAttributesForReschedule(),
            ['appointment_at' => $data['appointment_at'] ?? null],
        ));
        $new->forceFill([
            'organization_id' => $completed->organization_id,
            'status' => AppointmentStatus::Scheduled->value,
            'origin_type' => 'appointment',
            'origin_id' => $completed->getKey(),
        ]);
        $new->save();

        return $new;
    }

    private function requireLeadForAppointment(Appointment $appointment, array $data): Lead
    {
        $leadId = $data['lead_id'] ?? null;

        if ($leadId === null) {
            throw new LogicException(
                'A Demo/Proposal transition requires an existing Lead (a valid requirement) — none was supplied.'
            );
        }

        $lead = Lead::query()->whereKey($leadId)->first();

        if ($lead === null || $lead->prospect_id !== $appointment->prospect_id) {
            throw new LogicException("Lead #{$leadId} is not a valid requirement for this Appointment's Company.");
        }

        return $lead;
    }

    /**
     * The single shared entry point for every valid route into Demo
     * (Follow-Up/Appointment/Lead/Proposal -> Demo per the Master BA) —
     * never created merely because a Lead enum value changes.
     *
     * @param  array<string, mixed>  $data  must include demo_at, mode, and mode-specific location/meeting_link
     */
    public function transitionToDemo(Lead $lead, Appointment|FollowUp|Lead|Proposal|Demo $origin, string $originAlias, array $data): Demo
    {
        return DB::transaction(function () use ($lead, $origin, $originAlias, $data) {
            if (blank($data['demo_at'] ?? null)) {
                throw new LogicException('A Demo requires a Demo date/time.');
            }

            $mode = $data['mode'] instanceof DemoMode ? $data['mode'] : DemoMode::from((string) ($data['mode'] ?? ''));

            if ($mode === DemoMode::OnSite && blank($data['location'] ?? null)) {
                throw new LogicException('An on-site Demo requires a Location.');
            }

            if ($mode === DemoMode::Online && blank($data['meeting_link'] ?? null)) {
                throw new LogicException('An online Demo requires a Meeting Link.');
            }

            $demo = new Demo([
                'prospect_id' => $lead->prospect_id,
                'lead_id' => $lead->getKey(),
                'assigned_to' => $data['assigned_to'] ?? $lead->assigned_to,
                'created_by' => $data['created_by'] ?? auth()->id() ?? $lead->created_by,
                'demo_at' => $data['demo_at'],
                'mode' => $mode->value,
                'location' => $data['location'] ?? null,
                'meeting_link' => $data['meeting_link'] ?? null,
                'attendees' => $data['attendees'] ?? null,
                'product_service' => $data['product_service'] ?? null,
                'purpose' => $data['purpose'] ?? null,
                'status' => DemoStatus::Scheduled->value,
            ]);
            $demo->forceFill(['origin_type' => $originAlias, 'origin_id' => $origin->getKey()]);
            $demo->save();

            AuditLogger::record(
                entityType: 'Demo',
                entityId: $demo->getKey(),
                action: 'demo_created',
                organizationId: $demo->organization_id,
                before: null,
                after: ['origin_type' => $originAlias, 'origin_id' => $origin->getKey(), 'lead_id' => $lead->getKey()],
            );

            return $demo;
        });
    }

    /**
     * @param  array<string, mixed>  $data  destination-specific fields the resolved next_action requires
     *     ('reason'/'notes' for Another Demo Required's new schedule, 'clarification_notes' for Requirement
     *     Clarification, 'follow_up_at'/'reason' for a Demo Follow-Up, etc.), plus 'next_action' when the
     *     outcome is non-deterministic (Interested/OK, Correction Needed, Other).
     */
    public function transitionDemoOutcome(Demo $demo, DemoOutcome $outcome, array $data): void
    {
        DB::transaction(function () use ($demo, $outcome, $data) {
            $locked = Demo::query()->whereKey($demo->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status?->value !== DemoStatus::Scheduled->value) {
                throw new LogicException(
                    "Demo #{$locked->getKey()} cannot be transitioned: its status is ".
                    ($locked->status?->value ?? 'unset').', not scheduled.'
                );
            }

            if ($outcome === DemoOutcome::CorrectionNeeded && blank($data['correction_comments'] ?? null)) {
                throw new LogicException('Correction Needed requires correction/customer comments.');
            }

            if ($outcome === DemoOutcome::Other && blank($data['notes'] ?? null)) {
                throw new LogicException('Outcome "Other" requires Notes.');
            }

            $nextAction = $this->resolveDemoNextAction($outcome, $data);

            $locked->forceFill([
                'outcome' => $outcome->value,
                'next_action' => $nextAction->value,
                'status' => DemoStatus::Completed->value,
                'correction_comments' => $data['correction_comments'] ?? $locked->correction_comments,
                'notes' => $data['notes'] ?? $locked->notes,
                'feedback' => $data['feedback'] ?? $locked->feedback,
            ])->save();

            $downstream = match ($nextAction) {
                DemoNextAction::StartProposal => $this->createProposalFromLead($locked->lead),
                DemoNextAction::ScheduleAnotherDemo => $this->createRepeatDemo($locked, $data),
                DemoNextAction::CreateFollowUp => $this->createFollowUpFromOrigin($locked, 'demo', $data),
                DemoNextAction::ReturnToLeadForClarification => $this->returnLeadToRequirementCollection($locked, $data),
                DemoNextAction::NoFurtherAction => null,
            };

            AuditLogger::record(
                entityType: 'Demo',
                entityId: $locked->getKey(),
                action: 'demo_outcome_recorded',
                organizationId: $locked->organization_id,
                before: ['status' => DemoStatus::Scheduled->value, 'outcome' => null, 'next_action' => null],
                after: [
                    'status' => DemoStatus::Completed->value,
                    'outcome' => $outcome->value,
                    'next_action' => $nextAction->value,
                    'downstream_type' => $downstream ? class_basename($downstream) : null,
                    'downstream_id' => $downstream?->getKey(),
                ],
            );
        });
    }

    /**
     * Validates the supplied/derived next_action against the exact
     * approved determinism table — a deterministic outcome always wins
     * (a contradictory user-supplied value is rejected, never silently
     * overridden or silently accepted); a non-deterministic outcome
     * requires the caller to supply one from its own allowed set.
     */
    private function resolveDemoNextAction(DemoOutcome $outcome, array $data): DemoNextAction
    {
        if ($outcome->isNextActionDeterministic()) {
            $deterministic = $outcome->deterministicNextAction();

            if (isset($data['next_action'])) {
                $supplied = $data['next_action'] instanceof DemoNextAction
                    ? $data['next_action']
                    : DemoNextAction::from((string) $data['next_action']);

                if ($supplied !== $deterministic) {
                    throw new LogicException(
                        "Outcome {$outcome->value} deterministically implies next_action ".
                        "{$deterministic->value} — {$supplied->value} was supplied and is rejected."
                    );
                }
            }

            return $deterministic;
        }

        $suppliedRaw = $data['next_action'] ?? null;

        if ($suppliedRaw === null) {
            throw new LogicException(
                "Outcome {$outcome->value} requires an explicit next_action selection."
            );
        }

        $supplied = $suppliedRaw instanceof DemoNextAction ? $suppliedRaw : DemoNextAction::from((string) $suppliedRaw);

        if (! in_array($supplied, $outcome->allowedNextActions(), true)) {
            throw new LogicException(
                "next_action {$supplied->value} is not a valid destination for outcome {$outcome->value}."
            );
        }

        return $supplied;
    }

    private function createRepeatDemo(Demo $completed, array $data): Demo
    {
        if (blank($data['demo_at'] ?? null)) {
            throw new LogicException('Scheduling another Demo requires a new Demo date/time.');
        }

        $mode = ($data['mode'] ?? $completed->mode) instanceof DemoMode
            ? ($data['mode'] ?? $completed->mode)
            : DemoMode::from((string) ($data['mode'] ?? $completed->mode->value));

        $new = new Demo(array_merge(
            $completed->replacementAttributesForReschedule(),
            [
                'mode' => $mode->value,
                'demo_at' => $data['demo_at'],
                'location' => $data['location'] ?? $completed->location,
                'meeting_link' => $data['meeting_link'] ?? $completed->meeting_link,
            ],
        ));
        $new->forceFill([
            'organization_id' => $completed->organization_id,
            'status' => DemoStatus::Scheduled->value,
            'origin_type' => 'demo',
            'origin_id' => $completed->getKey(),
        ]);
        $new->save();

        return $new;
    }

    /** Returns the SAME Lead to Requirement Collection — never a duplicate Lead. */
    private function returnLeadToRequirementCollection(Demo $demo, array $data): Lead
    {
        $lead = $demo->lead;

        $lead->update([
            'status' => LeadStatus::RequirementCollection->value,
            'notes' => $data['clarification_notes'] ?? $lead->notes,
        ]);

        return $lead;
    }

    /**
     * Proposal's schema is untouched in Phase 2 (out of scope) — it has no
     * origin_type/origin_id column. Lineage back to the originating
     * Demo/Appointment is captured in the AuditLogger event this method's
     * caller writes (downstream_type/downstream_id), not as a column here.
     */
    private function createProposalFromLead(Lead $lead): Proposal
    {
        return Proposal::query()->firstOrCreate(
            ['lead_id' => $lead->getKey()],
            [
                'prospect_id' => $lead->prospect_id,
                'assigned_to' => $lead->assigned_to,
                'created_by' => $lead->created_by,
                'stage' => ProposalStage::BeingPrepared->value,
            ]
        );
    }
}
