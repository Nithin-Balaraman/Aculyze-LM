<?php

namespace App\Services;

use App\Enums\AppointmentOutcome;
use App\Enums\AppointmentStage;
use App\Enums\AppointmentStatus;
use App\Enums\DemoMode;
use App\Enums\DemoNextAction;
use App\Enums\DemoOutcome;
use App\Enums\DemoStatus;
use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\ProposalContinuation;
use App\Enums\ProposalOutcome;
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

    private function createFollowUpFromOrigin(Appointment|Demo|Proposal $origin, string $originAlias, array $data): FollowUp
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

    /**
     * Phase 3: a standalone, direct Lead status change — not a side-effect
     * of an Appointment/Demo outcome (those already set Lead status
     * themselves where relevant, e.g. returnLeadToRequirementCollection()).
     * This is the entry point for LeadResource's own "Update Status" action
     * and PipelineBoard's Lead same-lane drag, once a destination stage
     * maps to a genuine status change. Legacy `stage` is left untouched —
     * consistent with every other normal Phase 3 business transition.
     *
     * @param  array<string, mixed>  $data  optional 'notes' to persist alongside the status change
     */
    public function transitionLeadStatus(Lead $lead, LeadStatus $status, array $data = []): void
    {
        DB::transaction(function () use ($lead, $status, $data) {
            $locked = Lead::query()->whereKey($lead->getKey())->lockForUpdate()->firstOrFail();

            $before = $locked->status;

            $locked->forceFill([
                'status' => $status->value,
                'notes' => $data['notes'] ?? $locked->notes,
            ])->save();

            AuditLogger::record(
                entityType: 'Lead',
                entityId: $locked->getKey(),
                action: 'lead_status_changed',
                organizationId: $locked->organization_id,
                before: ['status' => $before?->value],
                after: ['status' => $status->value],
            );
        });
    }

    /**
     * Phase 3 correction: PipelineBoard's cross-drop drags a source card
     * (Appointment/Lead) onto a DIFFERENT lane, which creates a real
     * destination record there (a Follow-Up/Lead/Proposal/Demo) through its
     * own approved path — but the dragged SOURCE card also needs to be
     * finalized as a real business conclusion of its own (e.g. the
     * Appointment that led to that Lead/Proposal really did succeed). That
     * finalization is itself an ordinary business transition and must be
     * owned here, not left to PipelineBoard as a raw mutation — this is the
     * single centralized place deciding and persisting the Appointment's
     * normalized status/outcome, its legacy-stage compatibility echo (kept
     * only for PipelineBoard's own stage-grouped display and
     * StageDropoutReport's historical view — never read by any
     * business-rule/service logic), and its audit trail.
     *
     * Deliberately NOT transitionAppointmentOutcome(): that method's own
     * downstream creation (createFollowUpFromOrigin()/createLeadFromAppointment()/etc.)
     * would create a SECOND destination record on top of the one the
     * cross-drop's own destination-creation step already created — this
     * method only finalizes the source, it never creates anything.
     *
     * @param  array<string, mixed>  $data  optional 'outcome_notes' to persist
     */
    public function finalizeCrossDroppedAppointment(Appointment $appointment, array $data = []): void
    {
        DB::transaction(function () use ($appointment, $data) {
            $locked = Appointment::query()->whereKey($appointment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->stage->isTerminal()) {
                throw new LogicException("Appointment #{$locked->getKey()} has already been resolved.");
            }

            $before = ['stage' => $locked->stage->value, 'status' => $locked->status?->value];

            $locked->forceFill([
                'stage' => AppointmentStage::Succeeded,
                'status' => AppointmentStatus::Completed,
                'outcome_notes' => $data['outcome_notes'] ?? $locked->outcome_notes,
            ])->save();

            AuditLogger::record(
                entityType: 'Appointment',
                entityId: $locked->getKey(),
                action: 'appointment_resolved_via_cross_drop',
                organizationId: $locked->organization_id,
                before: $before,
                after: ['stage' => AppointmentStage::Succeeded->value, 'status' => AppointmentStatus::Completed->value],
            );
        });
    }

    /**
     * Phase 3 correction: the Lead counterpart to
     * finalizeCrossDroppedAppointment() — see that method's docblock for
     * why this is a centralized, single-purpose finalization rather than a
     * call into transitionLeadStatus()'s sibling machinery (there is no
     * downstream-creation conflict here since transitionLeadStatus() never
     * creates anything, but this is still kept as its own explicit method
     * so cross-drop finalization has one obvious, auditable owner distinct
     * from an ordinary Update Status change, and so it can also write the
     * legacy-stage compatibility echo transitionLeadStatus() deliberately
     * never touches).
     *
     * ProposalRequired — not LeadStatus::fromLegacyStage(Validated), which
     * would resolve to RequirementConfirmed — is the correct normalized
     * status: this Lead is, right now, actually getting a real Proposal out
     * of this exact cross-drop, so its status must reflect that, not the
     * conservative historical-backfill mapping meant for rows with no
     * other signal.
     *
     * @param  array<string, mixed>  $data  optional 'notes' to persist
     */
    public function finalizeCrossDroppedLead(Lead $lead, array $data = []): void
    {
        DB::transaction(function () use ($lead, $data) {
            $locked = Lead::query()->whereKey($lead->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->stage->isTerminal()) {
                throw new LogicException("Lead #{$locked->getKey()} has already been resolved.");
            }

            $before = ['stage' => $locked->stage->value, 'status' => $locked->status?->value];

            $locked->forceFill([
                'stage' => LeadStage::Validated,
                'status' => LeadStatus::ProposalRequired,
                'notes' => $data['notes'] ?? $locked->notes,
            ])->save();

            AuditLogger::record(
                entityType: 'Lead',
                entityId: $locked->getKey(),
                action: 'lead_resolved_via_cross_drop',
                organizationId: $locked->organization_id,
                before: $before,
                after: ['stage' => LeadStage::Validated->value, 'status' => LeadStatus::ProposalRequired->value],
            );
        });
    }

    /**
     * Phase 3: the approved, narrow set of next-step actions a Proposal can
     * generate — pure navigation/next-step creation, never a Proposal
     * lifecycle change (no approval/versioning/Outlook/PDF workflow —
     * Phase 4, out of scope). Eligibility: Won/Lost never allow an ordinary
     * continuation; Hold allows FollowUpRequired only (Hold is a pause, not
     * a final decision — Follow-Up is the approved review mechanism); any
     * other non-final state allows all three. Enforced here server-side,
     * not merely hidden in the UI — a direct call bypassing the UI with an
     * ineligible outcome is rejected identically.
     *
     * @param  array<string, mixed>  $data  destination-specific fields the chosen continuation requires
     */
    public function transitionProposalContinuation(Proposal $proposal, ProposalContinuation $continuation, array $data = []): FollowUp|Demo|Lead
    {
        return DB::transaction(function () use ($proposal, $continuation, $data) {
            $locked = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($locked->outcome, [ProposalOutcome::Won, ProposalOutcome::Lost], true)) {
                throw new LogicException(
                    "Proposal #{$locked->getKey()} cannot use an ordinary continuation action: its outcome is {$locked->outcome->getLabel()}."
                );
            }

            if ($locked->outcome === ProposalOutcome::Hold && $continuation !== ProposalContinuation::FollowUpRequired) {
                throw new LogicException(
                    "Proposal #{$locked->getKey()} is on Hold — only Follow-Up Required is a valid continuation while on Hold."
                );
            }

            $result = match ($continuation) {
                ProposalContinuation::FollowUpRequired => $this->createFollowUpFromOrigin($locked, 'proposal', $data),
                ProposalContinuation::DemoRequired => $this->transitionToDemo($this->requireLeadForProposal($locked), $locked, 'proposal', $data),
                ProposalContinuation::RequirementClarificationRequired => $this->returnLeadFromProposalForClarification($locked, $data),
            };

            AuditLogger::record(
                entityType: 'Proposal',
                entityId: $locked->getKey(),
                action: 'proposal_continuation_'.$continuation->value,
                organizationId: $locked->organization_id,
                before: ['outcome' => $locked->outcome?->value],
                after: [
                    'continuation' => $continuation->value,
                    'downstream_type' => class_basename($result),
                    'downstream_id' => $result->getKey(),
                ],
            );

            return $result;
        });
    }

    private function requireLeadForProposal(Proposal $proposal): Lead
    {
        $lead = $proposal->lead;

        if ($lead === null) {
            throw new LogicException("Proposal #{$proposal->getKey()} has no Lead to transition to Demo.");
        }

        return $lead;
    }

    /** Returns the SAME Lead to Requirement Collection — never a duplicate Lead, mirrors returnLeadToRequirementCollection(). */
    private function returnLeadFromProposalForClarification(Proposal $proposal, array $data): Lead
    {
        $lead = $this->requireLeadForProposal($proposal);

        $lead->update([
            'status' => LeadStatus::RequirementCollection->value,
            'notes' => $data['clarification_notes'] ?? $lead->notes,
        ]);

        return $lead;
    }
}
