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
 * "Follow Up At" and "Appointment At" on the Call Record form: visible AND
 * required only for the outcomes that actually route to that downstream
 * record (driven by CallOutcome::routesToFollowUp()/routesToAppointment()
 * directly, so they can never drift out of sync with the real routing
 * rules), and set on the auto-created Follow-Up/Appointment by
 * CallRoutingService — see CallRoutingTest for the lower-level service
 * coverage of the same data flow.
 *
 * The model-level "exempt auto-routed insert" guards on FollowUp/
 * Appointment (see AppointmentMandatoryFieldsTest/FollowUpMandatoryFieldsTest)
 * are deliberately untouched by this requirement — they cover write paths
 * other than this form (tests, seeders, future imports/backfills), which
 * may legitimately not know the date at insert time.
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
            // Phase 3: No Answer / Switched Off / Not Reachable no longer
            // show (or route to) a Follow-Up at all — see CallRoutingTest.
            // Concerned Person Not Available / Profile Requested still show
            // the field (an optional, intentional Follow-Up), just no
            // longer require it — see followUpAtRequiredOutcomes() below.
            'Callback Requested' => [CallOutcome::CallbackRequested],
            'Concerned Person Not Available' => [CallOutcome::ConcernedPersonNotAvailable],
            'Profile Requested' => [CallOutcome::ProfileRequested],
        ];
    }

    public static function appointmentRoutingOutcomes(): array
    {
        return [
            // Phase 3: Requirement Identified no longer routes to an
            // Appointment (it creates a Lead only) — see CallRoutingTest.
            'Appointment Set' => [CallOutcome::AppointmentSet],
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

    /**
     * Phase 3: No Answer / Switched Off / Not Reachable no longer create any
     * downstream activity at all — the date fields must not appear for them
     * either now.
     */
    public function test_neither_date_field_is_visible_for_no_answer_switched_off_or_not_reachable(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        foreach ([CallOutcome::NoAnswer, CallOutcome::SwitchedOff, CallOutcome::NotReachable] as $outcome) {
            Livewire::test(CreateCallRecord::class)
                ->fillForm($this->baseFormData(['outcome' => $outcome->value]))
                ->assertDontSee('Follow Up At')
                ->assertDontSee('Appointment At');
        }
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
                'notes' => 'Asked to call back in a couple of days.',
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
                'notes' => 'Agreed to a site visit.',
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $appointment = Appointment::sole();
        $this->assertSame($appointmentAt->format('Y-m-d H:i:s'), $appointment->appointment_at->format('Y-m-d H:i:s'));
    }

    public function test_leaving_follow_up_at_blank_fails_validation_for_a_follow_up_routing_outcome(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateCallRecord::class)
            ->fillForm($this->baseFormData([
                'outcome' => CallOutcome::CallbackRequested->value,
                'notes' => 'Asked to call back next week.',
                'follow_up_at' => null,
            ]))
            ->call('create')
            ->assertHasFormErrors(['follow_up_at']);

        $this->assertDatabaseCount('call_records', 0);
    }

    /**
     * Phase 3: Concerned Person Not Available / Profile Requested's
     * Follow-Up is optional/intentional only — leaving it blank must NOT
     * fail validation for these two outcomes (unlike Callback Requested).
     */
    public function test_leaving_follow_up_at_blank_succeeds_for_conditional_follow_up_outcomes(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateCallRecord::class)
            ->fillForm($this->baseFormData([
                'outcome' => CallOutcome::ConcernedPersonNotAvailable->value,
                'notes' => 'Decision-maker was out of office.',
                'follow_up_at' => null,
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('call_records', 1);
    }

    public function test_leaving_appointment_at_blank_fails_validation_for_an_appointment_routing_outcome(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateCallRecord::class)
            ->fillForm($this->baseFormData(['outcome' => CallOutcome::AppointmentSet->value, 'appointment_at' => null]))
            ->call('create')
            ->assertHasFormErrors(['appointment_at']);

        $this->assertDatabaseCount('call_records', 0);
    }

    /**
     * Phase 3: Requirement Identified creates a Lead ONLY — no Appointment
     * is automatically created, and the Appointment At field is not even
     * shown for this outcome anymore (see appointmentRoutingOutcomes()).
     */
    public function test_requirement_identified_creates_the_lead_only_with_no_appointment_field(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateCallRecord::class)
            ->fillForm($this->baseFormData([
                'outcome' => CallOutcome::RequirementIdentified->value,
                'notes' => 'Interested in a full rollout.',
            ]))
            ->assertDontSee('Appointment At')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(0, Appointment::count());
        $this->assertDatabaseCount('leads', 1);
    }
}
