<?php

namespace App\Services;

use App\Enums\AppointmentStage;
use App\Enums\AppointmentStatus;
use App\Enums\CallNextAction;
use App\Enums\CallOutcome;
use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Models\Appointment;
use App\Models\CallRecord;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Centralizes what happens after a Call Record is saved. This is the single
 * place that decides which downstream records (Follow-Up / Appointment /
 * Lead) a Call Outcome creates — see AGENTS.md sections 15 and 46.
 *
 * Deliberately NOT inline in a Filament resource or model observer callback
 * body, so the routing rules stay easy to find, read, and unit test.
 */
class CallRoutingService
{
    /**
     * Process a newly created Call Record's outcome, creating whichever
     * downstream records it implies. Safe to call more than once for the
     * same Call Record — it is a no-op after the first successful run
     * (AGENTS.md section 16: no duplicate routing on retries/re-saves).
     */
    public function route(CallRecord $callRecord): void
    {
        if ($callRecord->processed_at !== null) {
            return;
        }

        DB::transaction(function () use ($callRecord) {
            // Re-fetch and lock inside the transaction so two concurrent
            // requests processing the same call can't both pass the
            // processed_at check above and double-create records.
            $locked = CallRecord::query()->whereKey($callRecord->id)->lockForUpdate()->firstOrFail();

            if ($locked->processed_at !== null) {
                return;
            }

            $this->routeDownstream($locked);

            $locked->forceFill(['processed_at' => now()])->save();
            $callRecord->processed_at = $locked->processed_at;
        });
    }

    /**
     * The single routing decision table — which downstream record(s) an
     * outcome (plus, for Other, its next_action) implies. Shared by the
     * initial creation-time route() above and the explicit Correct Outcome
     * reconciliation below, so the two paths can never diverge.
     */
    private function routeDownstream(CallRecord $locked): void
    {
        $outcome = $locked->outcome;

        if ($outcome->routesToFollowUp()) {
            $this->createFollowUp($locked);
        }

        // Phase 3: Concerned Person Not Available / Profile Requested no
        // longer auto-create a Follow-Up — only when the caller explicitly
        // supplied a Follow-Up date/time (follow_up_at), signalling a real,
        // intentional callback/Follow-Up decision rather than the outcome
        // alone.
        if ($outcome->routesToConditionalFollowUp() && filled($locked->follow_up_at)) {
            $this->createFollowUp($locked);
        }

        if ($outcome->routesToAppointment()) {
            $this->createAppointment($locked);
        }

        if ($outcome->routesToLead()) {
            $this->createLead($locked);
        }

        // Phase 3: Other's downstream creation (if any) is driven by the
        // explicit, constrained CallNextAction the user selected — never by
        // the outcome alone (Other itself routesNowhere()).
        if ($outcome === CallOutcome::Others) {
            match ($locked->next_action) {
                CallNextAction::CreateFollowUp => $this->createFollowUp($locked),
                CallNextAction::CreateAppointment => $this->createAppointment($locked),
                CallNextAction::CreateLead => $this->createLead($locked),
                CallNextAction::NoFurtherAction, null => null,
            };
        }
    }

    /**
     * Phase 3: explicit, intentional correction of a Call's recorded
     * outcome — a separate business action from ordinary editing (see
     * CallRecordObserver, which only routes on `created()`; this method is
     * the only other place routing may ever run).
     *
     * Safety boundary: if ANY downstream record already exists for this
     * Call (from the original creation or an earlier correction), the
     * correction is rejected outright — never silently reconciled, deleted,
     * or reused, even when the old and new outcomes would create the same
     * downstream type. No once-ever limit: a Call with no downstream
     * history may be corrected repeatedly; the moment a correction creates
     * downstream work, that record becomes the permanent blocker against
     * any further ordinary correction.
     *
     * @param  array<string, mixed>  $data  destination-specific fields the corrected outcome requires
     */
    public function correctOutcome(CallRecord $callRecord, CallOutcome $correctedOutcome, string $correctionReason, array $data = []): CallRecord
    {
        return DB::transaction(function () use ($callRecord, $correctedOutcome, $correctionReason, $data) {
            $locked = CallRecord::query()->whereKey($callRecord->id)->lockForUpdate()->firstOrFail();

            if ($locked->outcome === $correctedOutcome) {
                throw new LogicException('The requested corrected outcome is the same as the current outcome — nothing to correct.');
            }

            $blockers = array_filter($locked->deletionBlockers());

            if ($blockers !== []) {
                AuditLogger::record(
                    entityType: 'CallRecord',
                    entityId: $locked->getKey(),
                    action: 'call_outcome_correction_rejected',
                    organizationId: $locked->organization_id,
                    before: ['outcome' => $locked->outcome->value],
                    after: ['attempted_outcome' => $correctedOutcome->value, 'blockers' => array_keys($blockers), 'reason' => $correctionReason],
                );

                throw new LogicException(
                    'This Call already has downstream business history ('.implode(', ', array_keys($blockers)).
                    ') and cannot be corrected through the ordinary Correct Outcome action. '.
                    'An exceptional historical/data-correction process is required instead.'
                );
            }

            if (blank($correctionReason)) {
                throw new LogicException('Correcting a Call outcome requires a correction reason.');
            }

            $priorOutcome = $locked->outcome;

            $locked->forceFill(array_merge($data, [
                'outcome' => $correctedOutcome,
                'correction_reason' => $correctionReason,
                'outcome_corrected_at' => now(),
            ]))->save();

            $this->routeDownstream($locked);

            $downstream = $locked->deletionBlockers();

            AuditLogger::record(
                entityType: 'CallRecord',
                entityId: $locked->getKey(),
                action: 'call_outcome_corrected',
                organizationId: $locked->organization_id,
                before: ['outcome' => $priorOutcome->value],
                after: [
                    'outcome' => $correctedOutcome->value,
                    'correction_reason' => $correctionReason,
                    'downstream' => array_keys(array_filter($downstream)),
                ],
            );

            return $locked;
        });
    }

    private function createFollowUp(CallRecord $callRecord): void
    {
        FollowUp::query()->firstOrCreate(
            ['call_record_id' => $callRecord->id],
            [
                'prospect_id' => $callRecord->prospect_id,
                'user_id' => $callRecord->prospect->assigned_to,
                'follow_up_at' => $callRecord->follow_up_at,
                'reason' => $callRecord->outcome->getLabel(),
                'status' => 'pending',
            ]
        );
    }

    private function createAppointment(CallRecord $callRecord): void
    {
        Appointment::query()->firstOrCreate(
            ['call_record_id' => $callRecord->id],
            [
                'prospect_id' => $callRecord->prospect_id,
                'assigned_to' => $callRecord->prospect->assigned_to,
                'created_by' => $callRecord->user_id,
                'appointment_at' => $callRecord->appointment_at,
                'stage' => AppointmentStage::AppointmentMade->value,
                'status' => AppointmentStatus::Scheduled->value,
            ]
        );
    }

    private function createLead(CallRecord $callRecord): void
    {
        Lead::query()->firstOrCreate(
            ['call_record_id' => $callRecord->id],
            [
                'prospect_id' => $callRecord->prospect_id,
                'assigned_to' => $callRecord->prospect->assigned_to,
                'created_by' => $callRecord->user_id,
                'stage' => LeadStage::RequirementCollection->value,
                'status' => LeadStatus::RequirementCollection->value,
                'temperature' => LeadTemperature::Warm->value,
                'requirement_details' => $callRecord->notes,
            ]
        );
    }
}
