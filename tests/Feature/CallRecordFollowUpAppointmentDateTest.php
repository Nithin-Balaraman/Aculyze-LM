<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Filament\Resources\CallRecordResource\Pages\CreateCallRecord;
use App\Models\Appointment;
use App\Models\FollowUp;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * "Follow Up At" and "Appointment At" on the Call Record form: visible only
 * for the outcomes that actually route to that downstream record (driven
 * by CallOutcome::routesToFollowUp()/routesToAppointment() directly, so
 * they can never drift out of sync with the real routing rules), optional,
 * and set on the auto-created Follow-Up/Appointment by
 * CallRoutingService — see CallRoutingTest for the lower-level service
 * coverage of the same data flow.
 */
class CallRecordFollowUpAppointmentDateTest extends TestCase
{
    use RefreshDatabase;

    private function baseFormData(array $overrides = []): array
    {
        $prospect = Prospect::factory()->create();

        return array_merge([
            'prospect_id' => $prospect->id,
            'called_at' => now()->format('Y-m-d H:i:s'),
            'outcome' => CallOutcome::NoAnswer->value,
        ], $overrides);
    }

    public static function followUpRoutingOutcomes(): array
    {
        return [
            'No Answer' => [CallOutcome::NoAnswer],
            'Switched Off' => [CallOutcome::SwitchedOff],
            'Not Reachable' => [CallOutcome::NotReachable],
            'Callback Requested' => [CallOutcome::CallbackRequested],
            'Concerned Person Not Available' => [CallOutcome::ConcernedPersonNotAvailable],
            'Profile Requested' => [CallOutcome::ProfileRequested],
        ];
    }

    public static function appointmentRoutingOutcomes(): array
    {
        return [
            'Appointment Set' => [CallOutcome::AppointmentSet],
            'Requirement Identified' => [CallOutcome::RequirementIdentified],
        ];
    }

    #[DataProvider('followUpRoutingOutcomes')]
    public function test_follow_up_at_field_is_visible_and_appointment_at_is_not_for_follow_up_routing_outcomes(CallOutcome $outcome): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateCallRecord::class)
            ->fillForm($this->baseFormData(['outcome' => $outcome->value]))
            ->assertSee('Follow Up At')
            ->assertDontSee('Appointment At');
    }

    #[DataProvider('appointmentRoutingOutcomes')]
    public function test_appointment_at_field_is_visible_and_follow_up_at_is_not_for_appointment_routing_outcomes(CallOutcome $outcome): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateCallRecord::class)
            ->fillForm($this->baseFormData(['outcome' => $outcome->value]))
            ->assertSee('Appointment At')
            ->assertDontSee('Follow Up At');
    }

    public function test_neither_date_field_is_visible_for_outcomes_that_route_nowhere(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateCallRecord::class)
            ->fillForm($this->baseFormData(['outcome' => CallOutcome::FutureOpportunity->value]))
            ->assertDontSee('Follow Up At')
            ->assertDontSee('Appointment At');
    }

    public function test_setting_follow_up_at_on_the_form_sets_it_on_the_auto_created_follow_up(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $followUpAt = now()->addDays(2)->seconds(0);

        Livewire::test(CreateCallRecord::class)
            ->fillForm($this->baseFormData([
                'outcome' => CallOutcome::CallbackRequested->value,
                'follow_up_at' => $followUpAt->format('Y-m-d H:i:s'),
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $followUp = FollowUp::sole();
        $this->assertSame($followUpAt->format('Y-m-d H:i:s'), $followUp->follow_up_at->format('Y-m-d H:i:s'));
    }

    public function test_setting_appointment_at_on_the_form_sets_it_on_the_auto_created_appointment(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $appointmentAt = now()->addDays(3)->seconds(0);

        Livewire::test(CreateCallRecord::class)
            ->fillForm($this->baseFormData([
                'outcome' => CallOutcome::AppointmentSet->value,
                'appointment_at' => $appointmentAt->format('Y-m-d H:i:s'),
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $appointment = Appointment::sole();
        $this->assertSame($appointmentAt->format('Y-m-d H:i:s'), $appointment->appointment_at->format('Y-m-d H:i:s'));
    }

    public function test_leaving_follow_up_at_blank_is_accepted_and_the_follow_up_has_no_date(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateCallRecord::class)
            ->fillForm($this->baseFormData(['outcome' => CallOutcome::NoAnswer->value]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(FollowUp::sole()->follow_up_at);
    }

    public function test_leaving_appointment_at_blank_is_accepted_and_the_appointment_has_no_date(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateCallRecord::class)
            ->fillForm($this->baseFormData(['outcome' => CallOutcome::AppointmentSet->value]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(Appointment::sole()->appointment_at);
    }

    /**
     * Requirement Identified routes to both an Appointment and a Lead —
     * confirms the date only applies to the Appointment side and the Lead
     * (which has no date concept at all) is created normally alongside it.
     */
    public function test_requirement_identified_sets_appointment_at_and_creates_the_lead_normally(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $appointmentAt = now()->addDays(5)->seconds(0);

        Livewire::test(CreateCallRecord::class)
            ->fillForm($this->baseFormData([
                'outcome' => CallOutcome::RequirementIdentified->value,
                'appointment_at' => $appointmentAt->format('Y-m-d H:i:s'),
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $appointment = Appointment::sole();
        $this->assertSame($appointmentAt->format('Y-m-d H:i:s'), $appointment->appointment_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('leads', 1);
    }
}
