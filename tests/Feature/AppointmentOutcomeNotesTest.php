<?php

namespace Tests\Feature;

use App\Enums\AppointmentStage;
use App\Filament\Resources\AppointmentResource\Pages\CreateAppointment;
use App\Filament\Resources\AppointmentResource\Pages\EditAppointment;
use App\Models\Appointment;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * An Appointment may only be saved at a terminal Stage (Succeeded or Not
 * Succeeded) when Outcome Notes is genuinely present — mirrors
 * CallRecordOthersNotesTest (Notes required for outcomes where something
 * needs documenting).
 */
class AppointmentOutcomeNotesTest extends TestCase
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

    private function appointment(User $owner, AppointmentStage $stage, ?string $outcomeNotes = null): Appointment
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);

        return Appointment::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'appointment_at' => now(),
            'stage' => $stage,
            'outcome_notes' => $outcomeNotes,
        ]);
    }

    public function test_creating_an_appointment_in_a_non_terminal_stage_does_not_require_outcome_notes(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateAppointment::class)
            ->fillForm($this->baseFormData(['stage' => AppointmentStage::VisitConducted->value]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_creating_a_succeeded_appointment_without_outcome_notes_fails_validation(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateAppointment::class)
            ->fillForm($this->baseFormData(['stage' => AppointmentStage::Succeeded->value, 'outcome_notes' => null]))
            ->call('create')
            ->assertHasFormErrors(['outcome_notes']);

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_creating_a_not_succeeded_appointment_without_outcome_notes_fails_validation(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateAppointment::class)
            ->fillForm($this->baseFormData(['stage' => AppointmentStage::NotSucceeded->value, 'outcome_notes' => null]))
            ->call('create')
            ->assertHasFormErrors(['outcome_notes']);

        $this->assertDatabaseCount('appointments', 0);
    }

    /**
     * Regression guard: fillForm() seeds raw scalars, but a real Select
     * interaction rehydrates $get('stage') as the actual AppointmentStage
     * enum case, not its string value — mirrors the same guard in
     * CallRecordOthersNotesTest.
     */
    public function test_creating_a_succeeded_appointment_without_outcome_notes_fails_validation_when_stage_is_set_as_the_hydrated_enum_case(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateAppointment::class)
            ->fillForm($this->baseFormData(['outcome_notes' => null]))
            ->set('data.stage', AppointmentStage::Succeeded)
            ->call('create')
            ->assertHasFormErrors(['outcome_notes']);

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_creating_a_succeeded_appointment_with_whitespace_only_outcome_notes_fails_validation(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateAppointment::class)
            ->fillForm($this->baseFormData(['stage' => AppointmentStage::Succeeded->value, 'outcome_notes' => "   \n\t  "]))
            ->call('create')
            ->assertHasFormErrors(['outcome_notes']);

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_creating_a_succeeded_appointment_with_valid_outcome_notes_succeeds(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateAppointment::class)
            ->fillForm($this->baseFormData(['stage' => AppointmentStage::Succeeded->value, 'outcome_notes' => 'Deal closed, PO to follow.']))
            ->call('create')
            ->assertHasNoFormErrors();

        $appointment = Appointment::sole();
        $this->assertSame(AppointmentStage::Succeeded, $appointment->stage);
        $this->assertSame('Deal closed, PO to follow.', $appointment->outcome_notes);
    }

    public function test_editing_an_appointment_into_not_succeeded_without_outcome_notes_fails_validation(): void
    {
        $employee = User::factory()->create();
        $appointment = $this->appointment($employee, AppointmentStage::AppointmentMade);
        $this->actingAs($employee);

        Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
            ->fillForm(['stage' => AppointmentStage::NotSucceeded->value, 'outcome_notes' => null])
            ->call('save')
            ->assertHasFormErrors(['outcome_notes']);

        $this->assertSame(AppointmentStage::AppointmentMade, $appointment->fresh()->stage);
    }

    public function test_editing_an_appointment_into_not_succeeded_with_outcome_notes_succeeds(): void
    {
        $employee = User::factory()->create();
        $appointment = $this->appointment($employee, AppointmentStage::AppointmentMade);
        $this->actingAs($employee);

        Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
            ->fillForm(['stage' => AppointmentStage::NotSucceeded->value, 'outcome_notes' => 'Budget frozen this quarter.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $appointment->refresh();
        $this->assertSame(AppointmentStage::NotSucceeded, $appointment->stage);
        $this->assertSame('Budget frozen this quarter.', $appointment->outcome_notes);
    }

    /**
     * Defense in depth: the model guard must reject a terminal-stage save
     * even when it bypasses the Filament form entirely.
     */
    public function test_model_guard_rejects_a_succeeded_appointment_without_outcome_notes(): void
    {
        $this->expectException(\LogicException::class);

        $admin = User::factory()->admin()->create();
        $this->appointment($admin, AppointmentStage::Succeeded, null);
    }

    public function test_model_guard_rejects_a_not_succeeded_appointment_without_outcome_notes(): void
    {
        $this->expectException(\LogicException::class);

        $admin = User::factory()->admin()->create();
        $this->appointment($admin, AppointmentStage::NotSucceeded, null);
    }

    /**
     * Regression guard: Reassign and Mark Lost update unrelated fields and
     * must keep working on a pre-existing terminal-stage Appointment whose
     * outcome_notes is blank (grandfathered data from before this guard
     * existed) — mirrors the equivalent regression coverage in
     * AppointmentMandatoryFieldsTest for the appointment_at guard.
     */
    public function test_reassigning_a_pre_existing_succeeded_appointment_with_blank_outcome_notes_still_succeeds(): void
    {
        $admin = User::factory()->admin()->create();
        $appointment = Appointment::withoutEvents(fn () => Appointment::create([
            'prospect_id' => Prospect::factory()->create()->id,
            'assigned_to' => $admin->id,
            'created_by' => $admin->id,
            'appointment_at' => now(),
            'stage' => AppointmentStage::Succeeded,
        ]));

        $newOwner = User::factory()->create();
        $appointment->update(['assigned_to' => $newOwner->id]);

        $this->assertSame($newOwner->id, $appointment->fresh()->assigned_to);
    }
}
