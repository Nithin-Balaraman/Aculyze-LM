<?php

namespace App\Services;

use App\Enums\AppointmentStage;
use App\Enums\AppointmentStatus;
use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Models\Appointment;
use App\Models\CallRecord;
use App\Models\FollowUp;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;

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

            $outcome = $locked->outcome;

            if ($outcome->routesToFollowUp()) {
                $this->createFollowUp($locked);
            }

            if ($outcome->routesToAppointment()) {
                $this->createAppointment($locked);
            }

            if ($outcome->routesToLead()) {
                $this->createLead($locked);
            }

            $locked->forceFill(['processed_at' => now()])->save();
            $callRecord->processed_at = $locked->processed_at;
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
