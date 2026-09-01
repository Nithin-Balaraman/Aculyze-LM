<?php

namespace Tests\Feature;

use App\Enums\AppointmentStage;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Prospect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3: Appointment's stage->status compatibility fallback is a
 * defensive LEGACY-CREATION path only, never the normal Phase 3
 * architecture — see Appointment::booted()'s saving() hook. Ordinary
 * business workflow (WorkflowTransitionService) always writes `status`
 * explicitly and never depends on this fallback.
 */
class AppointmentStageStatusDerivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_stage_supplied_with_status_absent_derives_status_via_the_approved_mapping(): void
    {
        $prospect = Prospect::factory()->create();

        $appointment = Appointment::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->assigned_to,
            'appointment_at' => now()->addDay(),
            'stage' => AppointmentStage::AppointmentMade,
        ]);

        $this->assertSame(AppointmentStatus::Scheduled, $appointment->fresh()->status);
    }

    public function test_stage_supplied_with_an_explicit_matching_status_is_not_overwritten(): void
    {
        $prospect = Prospect::factory()->create();

        $appointment = Appointment::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->assigned_to,
            'appointment_at' => now()->addDay(),
            'stage' => AppointmentStage::AppointmentMade,
            'status' => AppointmentStatus::Scheduled,
        ]);

        $this->assertSame(AppointmentStatus::Scheduled, $appointment->fresh()->status);
    }

    /**
     * The exact shape every workflow-completed Appointment takes: `stage`
     * deliberately frozen (still AppointmentMade) while normalized `status`
     * has legitimately advanced to Completed. This is NOT a rejected
     * "conflict" — an explicit status is never second-guessed against
     * stage, since normalized status is the authoritative business state
     * and legacy stage is compatibility-only.
     */
    public function test_an_explicit_status_conflicting_with_what_stage_alone_would_imply_is_preserved_not_rejected(): void
    {
        $prospect = Prospect::factory()->create();

        $appointment = Appointment::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->assigned_to,
            'appointment_at' => now()->addDay(),
            'stage' => AppointmentStage::AppointmentMade,
            'status' => AppointmentStatus::Completed,
            'outcome_notes' => 'Deal closed on the spot.',
        ]);

        $this->assertSame(AppointmentStage::AppointmentMade, $appointment->fresh()->stage);
        $this->assertSame(AppointmentStatus::Completed, $appointment->fresh()->status);
    }

    /**
     * The fallback is create-only — an existing record's `status` is
     * always already loaded from the DB by normal Eloquent usage, so an
     * unrelated update must never retroactively force status/notes
     * requirements it never had.
     */
    public function test_fallback_does_not_apply_on_update_of_an_existing_record(): void
    {
        $prospect = Prospect::factory()->create();

        $appointment = Appointment::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->assigned_to,
            'appointment_at' => now()->addDay(),
            'stage' => AppointmentStage::AppointmentMade,
            'status' => AppointmentStatus::Scheduled,
        ]);

        $appointment->update(['stage' => AppointmentStage::VisitConducted]);

        // stage moved, but this is a legacy-stage-only progression with no
        // outcome captured — status is left exactly as it was (Scheduled),
        // never silently re-derived to Completed on an update.
        $this->assertSame(AppointmentStatus::Scheduled, $appointment->fresh()->status);
    }
}
