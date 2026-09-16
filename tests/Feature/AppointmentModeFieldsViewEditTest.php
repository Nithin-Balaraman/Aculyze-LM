<?php

namespace Tests\Feature;

use App\Enums\AppointmentMode;
use App\Enums\AppointmentStage;
use App\Filament\Resources\AppointmentResource\Pages\EditAppointment;
use App\Filament\Resources\AppointmentResource\Pages\ViewAppointment;
use App\Models\Appointment;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Display-only fix: Pipeline Board V2 (Calls column) added mode/
 * person_meeting/location to Appointment (see CallRoutingService::
 * createAppointment()), but AppointmentResource's own View/Edit pages
 * (both reuse AppointmentResource::formSchema() — see ViewAppointment,
 * a plain Filament ViewRecord that disables the same form) never surfaced
 * them. No creation/routing behavior changes here — this only makes
 * already-persisted values visible and, on Edit, changeable.
 */
class AppointmentModeFieldsViewEditTest extends TestCase
{
    use RefreshDatabase;

    private function makeAppointment(User $user, array $overrides = []): Appointment
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

        return Appointment::create(array_merge([
            'prospect_id' => $prospect->id,
            'assigned_to' => $user->id,
            'created_by' => $user->id,
            'appointment_at' => now()->addDay(),
            'stage' => AppointmentStage::AppointmentMade->value,
        ], $overrides));
    }

    public function test_view_page_shows_the_stored_mode_person_meeting_and_location(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $appointment = $this->makeAppointment($user, [
            'mode' => AppointmentMode::InPerson->value,
            'person_meeting' => 'Jane Procurement',
            'location' => 'Head Office, 3rd Floor',
        ]);

        Livewire::test(ViewAppointment::class, ['record' => $appointment->getKey()])
            ->assertFormSet([
                'mode' => AppointmentMode::InPerson->value,
                'person_meeting' => 'Jane Procurement',
                'location' => 'Head Office, 3rd Floor',
            ])
            ->assertFormFieldIsDisabled('mode')
            ->assertFormFieldIsDisabled('person_meeting')
            ->assertFormFieldIsDisabled('location');
    }

    public function test_a_legacy_appointment_predating_this_feature_shows_these_fields_blank_not_fabricated(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // No mode/person_meeting/location supplied — mirrors an
        // Appointment created before this feature existed, or via any
        // path other than Calls -> Appointment (standalone create,
        // Others + CreateAppointment).
        $appointment = $this->makeAppointment($user);

        Livewire::test(ViewAppointment::class, ['record' => $appointment->getKey()])
            ->assertFormSet([
                'mode' => null,
                'person_meeting' => null,
                'location' => null,
            ]);
    }

    public function test_edit_page_allows_changing_mode_person_meeting_and_location(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $appointment = $this->makeAppointment($user, [
            'mode' => AppointmentMode::Online->value,
            'person_meeting' => 'Original Contact',
        ]);

        Livewire::test(EditAppointment::class, ['record' => $appointment->getKey()])
            ->fillForm([
                'mode' => AppointmentMode::InPerson->value,
                'person_meeting' => 'Updated Contact',
                'location' => 'New Office Address',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $appointment->refresh();
        $this->assertSame(AppointmentMode::InPerson, $appointment->mode);
        $this->assertSame('Updated Contact', $appointment->person_meeting);
        $this->assertSame('New Office Address', $appointment->location);
    }
}
