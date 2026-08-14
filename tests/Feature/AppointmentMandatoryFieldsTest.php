<?php

namespace Tests\Feature;

use App\Enums\AppointmentStage;
use App\Enums\CallOutcome;
use App\Filament\Resources\AppointmentResource\Pages\CreateAppointment;
use App\Filament\Resources\AppointmentResource\Pages\EditAppointment;
use App\Models\Appointment;
use App\Models\CallRecord;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Mandatory Fields batch: Appointment At is required. Exempt only for the
 * very first insert coming from CallRoutingService (which never sets it —
 * the exact time often isn't known yet when a call sets one up); any later
 * save that actually sets it blank is still rejected, but unrelated updates
 * (Reassign, Mark Lost) that never touch it are unaffected.
 */
class AppointmentMandatoryFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function baseFormData(array $overrides = []): array
    {
        $prospect = Prospect::factory()->create();
        $employee = $prospect->assignedEmployee;

        return array_merge([
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'appointment_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'stage' => AppointmentStage::AppointmentMade->value,
        ], $overrides);
    }

    // --- Form-level ---

    public function test_creating_an_appointment_without_appointment_at_fails_validation(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateAppointment::class)
            ->fillForm($this->baseFormData(['appointment_at' => null]))
            ->call('create')
            ->assertHasFormErrors(['appointment_at']);

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_creating_an_appointment_with_appointment_at_succeeds(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateAppointment::class)
            ->fillForm($this->baseFormData())
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_editing_an_auto_routed_appointment_to_blank_appointment_at_fails_validation(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $employee->id,
            'called_at' => now(),
            'outcome' => CallOutcome::AppointmentSet,
        ]);
        $appointment = $call->fresh()->appointment;
        $this->assertNull($appointment->appointment_at);

        $this->actingAs($employee);

        Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
            ->fillForm(['appointment_at' => null])
            ->call('save')
            ->assertHasFormErrors(['appointment_at']);
    }

    // --- Model-level guard ---

    public function test_auto_routed_appointment_can_be_created_without_appointment_at(): void
    {
        $prospect = Prospect::factory()->create();

        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'called_at' => now(),
            'outcome' => CallOutcome::AppointmentSet,
        ]);

        $appointment = $call->fresh()->appointment;
        $this->assertNotNull($appointment);
        $this->assertNull($appointment->appointment_at);
    }

    public function test_model_guard_rejects_a_manually_created_appointment_without_appointment_at(): void
    {
        $this->expectException(\LogicException::class);

        $prospect = Prospect::factory()->create();

        Appointment::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'stage' => AppointmentStage::AppointmentMade,
        ]);
    }

    public function test_model_guard_rejects_explicitly_blanking_appointment_at_on_an_existing_record(): void
    {
        $prospect = Prospect::factory()->create();
        $appointment = Appointment::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'appointment_at' => now()->addDay(),
            'stage' => AppointmentStage::AppointmentMade,
        ]);

        $this->expectException(\LogicException::class);

        $appointment->update(['appointment_at' => null]);
    }

    /**
     * Regression guard: Reassign and Mark Lost update unrelated fields and
     * must keep working on an auto-routed Appointment whose appointment_at
     * is still blank.
     */
    public function test_reassigning_an_auto_routed_appointment_with_blank_appointment_at_still_succeeds(): void
    {
        $prospect = Prospect::factory()->create();
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'called_at' => now(),
            'outcome' => CallOutcome::RequirementIdentified,
        ]);
        $appointment = $call->fresh()->appointment;
        $this->assertNull($appointment->appointment_at);

        $newOwner = User::factory()->create();
        $appointment->update(['assigned_to' => $newOwner->id]);

        $this->assertSame($newOwner->id, $appointment->fresh()->assigned_to);
    }

    public function test_marking_an_auto_routed_appointment_lost_with_blank_appointment_at_still_succeeds(): void
    {
        $prospect = Prospect::factory()->create();
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'called_at' => now(),
            'outcome' => CallOutcome::AppointmentSet,
        ]);
        $appointment = $call->fresh()->appointment;
        $this->assertNull($appointment->appointment_at);

        $appointment->markLost('Prospect went with a competitor.');

        $this->assertTrue($appointment->fresh()->is_lost);
    }
}
