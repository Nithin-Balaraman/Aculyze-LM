<?php

namespace Tests\Feature;

use App\Enums\AppointmentStage;
use App\Enums\CallOutcome;
use App\Models\Appointment;
use App\Models\CallRecord;
use App\Models\Prospect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The one-off cleanup command for Appointments wrongly auto-created by the
 * pre-fix Future Opportunity routing bug (see CallRoutingTest::
 * test_future_opportunity_routes_nowhere). Simulates the "bad" historical
 * data directly (a Future Opportunity Call Record with an Appointment
 * already linked to it via call_record_id) rather than relying on the now-
 * fixed CallRoutingService, since that's exactly the leftover state this
 * command targets.
 */
class CleanupFutureOpportunityAppointmentsTest extends TestCase
{
    use RefreshDatabase;

    private function makeBadPair(): array
    {
        $prospect = Prospect::factory()->create();

        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'called_at' => now(),
            'outcome' => CallOutcome::FutureOpportunity,
            'processed_at' => now(),
        ]);

        $appointment = Appointment::create([
            'prospect_id' => $prospect->id,
            'call_record_id' => $call->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'stage' => AppointmentStage::AppointmentMade,
        ]);

        return [$call, $appointment];
    }

    private function makeLegitimateAppointment(): Appointment
    {
        $prospect = Prospect::factory()->create();

        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'called_at' => now(),
            'outcome' => CallOutcome::AppointmentSet,
        ]);

        return $call->fresh()->appointment;
    }

    public function test_reports_nothing_to_clean_up_when_no_bad_data_exists(): void
    {
        $this->makeLegitimateAppointment();

        $this->artisan('call-records:cleanup-future-opportunity-appointments')
            ->expectsOutputToContain('Nothing to do')
            ->assertExitCode(0);

        $this->assertSame(1, Appointment::count());
    }

    public function test_dry_run_lists_affected_appointments_without_deleting_anything(): void
    {
        [$call, $appointment] = $this->makeBadPair();
        $legitimate = $this->makeLegitimateAppointment();

        $this->artisan('call-records:cleanup-future-opportunity-appointments')
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain((string) $appointment->id)
            ->assertExitCode(0);

        $this->assertSame(2, Appointment::count());
        $this->assertDatabaseHas('appointments', ['id' => $appointment->id]);
        $this->assertDatabaseHas('appointments', ['id' => $legitimate->id]);
        $this->assertDatabaseHas('call_records', ['id' => $call->id]);
    }

    public function test_force_deletes_only_the_bad_appointments_and_leaves_call_records_and_legitimate_appointments_alone(): void
    {
        [$call, $appointment] = $this->makeBadPair();
        $legitimate = $this->makeLegitimateAppointment();

        $this->artisan('call-records:cleanup-future-opportunity-appointments', ['--force' => true])
            ->expectsOutputToContain('Deleted 1 Appointment')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('appointments', ['id' => $appointment->id]);
        $this->assertDatabaseHas('appointments', ['id' => $legitimate->id]);
        // The Call Record itself must never be touched by this cleanup.
        $this->assertDatabaseHas('call_records', ['id' => $call->id, 'outcome' => CallOutcome::FutureOpportunity->value]);
    }

    public function test_warns_when_a_bad_appointment_has_moved_past_its_initial_stage(): void
    {
        [, $appointment] = $this->makeBadPair();
        $appointment->update(['stage' => AppointmentStage::VisitConducted]);

        $this->artisan('call-records:cleanup-future-opportunity-appointments')
            ->expectsOutputToContain('moved past their initial')
            ->assertExitCode(0);
    }
}
