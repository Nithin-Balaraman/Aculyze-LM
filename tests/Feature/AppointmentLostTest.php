<?php

namespace Tests\Feature;

use App\Enums\AppointmentStage;
use App\Filament\Resources\AppointmentResource\Pages\ListAppointments;
use App\Models\Appointment;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Mirrors LeadLostTest: Appointment Lost is a designation layered on top of
 * an Appointment's current stage (not a stage itself), captures
 * lost_at_stage/lost_reason/lost_at, and follows the exact same pattern as
 * Lead::markLost().
 */
class AppointmentLostTest extends TestCase
{
    use RefreshDatabase;

    private function makeAppointment(AppointmentStage $stage = AppointmentStage::VisitConducted): Appointment
    {
        $prospect = Prospect::factory()->create();

        return Appointment::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'appointment_at' => now(),
            'stage' => $stage,
        ]);
    }

    public function test_mark_lost_captures_stage_reason_and_timestamp_without_changing_the_stage_field(): void
    {
        $appointment = $this->makeAppointment(AppointmentStage::VisitConducted);

        $appointment->markLost('Prospect went cold.');
        $appointment->refresh();

        $this->assertTrue($appointment->is_lost);
        $this->assertSame(AppointmentStage::VisitConducted, $appointment->lost_at_stage);
        $this->assertSame('Prospect went cold.', $appointment->lost_reason);
        $this->assertNotNull($appointment->lost_at);
        $this->assertSame(AppointmentStage::VisitConducted, $appointment->stage);
    }

    public function test_scope_lost_returns_only_lost_appointments(): void
    {
        $lost = $this->makeAppointment();
        $lost->markLost('Chose a competitor.');
        $this->makeAppointment();

        $this->assertSame(1, Appointment::query()->lost()->count());
        $this->assertTrue(Appointment::query()->lost()->first()->is($lost));
    }

    public function test_mark_lost_action_requires_a_reason(): void
    {
        $employee = User::factory()->create();
        $appointment = Appointment::create([
            'prospect_id' => Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id])->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'appointment_at' => now(),
            'stage' => AppointmentStage::AppointmentMade,
        ]);

        $this->actingAs($employee);

        Livewire::test(ListAppointments::class)
            ->callTableAction('markLost', $appointment, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);

        $this->assertFalse($appointment->fresh()->is_lost);
    }

    public function test_mark_lost_action_succeeds_with_a_reason_and_is_hidden_once_already_lost(): void
    {
        $employee = User::factory()->create();
        $appointment = Appointment::create([
            'prospect_id' => Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id])->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'appointment_at' => now(),
            'stage' => AppointmentStage::AppointmentMade,
        ]);

        $this->actingAs($employee);

        Livewire::test(ListAppointments::class)
            ->callTableAction('markLost', $appointment, data: ['reason' => 'No longer interested.'])
            ->assertHasNoTableActionErrors();

        $appointment->refresh();
        $this->assertTrue($appointment->is_lost);
        $this->assertSame('No longer interested.', $appointment->lost_reason);

        Livewire::test(ListAppointments::class)
            ->assertTableActionHidden('markLost', $appointment);
    }
}
