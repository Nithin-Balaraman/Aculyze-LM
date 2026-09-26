<?php

namespace Tests\Feature;

use App\Enums\CallNextAction;
use App\Enums\CallOutcome;
use App\Enums\ProfileSentStatus;
use App\Filament\Resources\CallRecordResource\Pages\CreateCallRecord;
use App\Filament\Resources\CallRecordResource\Pages\ViewCallRecord;
use App\Models\CallRecord;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Contact Person, Designation, and Phone Called are mandatory exactly for
 * the six outcomes that route to a real next step
 * (CallOutcome::requiresContactDetails()) and stay optional for the four
 * that don't (No Answer, Switched Off, Not Reachable, Others) — a new,
 * additional rule alongside CallRecordRequiresNotesTest's Notes coverage,
 * not a replacement for it.
 */
class CallRecordRequiresContactDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function baseFormData(array $overrides = []): array
    {
        $prospect = Prospect::factory()->create();

        return array_merge([
            'prospect_id' => $prospect->id,
            'called_at' => now()->format('Y-m-d H:i:s'),
            'outcome' => CallOutcome::NoAnswer->value,
            'notes' => 'Some notes.',
        ], $overrides);
    }

    public function test_callback_requested_without_contact_details_fails_validation(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateCallRecord::class)
            ->fillForm($this->baseFormData([
                'outcome' => CallOutcome::CallbackRequested->value,
                'follow_up_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'contact_person_spoken_to' => null,
                'designation' => null,
                'phone_called' => null,
            ]))
            ->call('create')
            ->assertHasFormErrors(['contact_person_spoken_to', 'designation', 'phone_called']);

        $this->assertDatabaseCount('call_records', 0);
    }

    public function test_callback_requested_with_contact_details_succeeds(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateCallRecord::class)
            ->fillForm($this->baseFormData([
                'outcome' => CallOutcome::CallbackRequested->value,
                'follow_up_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'contact_person_spoken_to' => 'Jane Doe',
                'designation' => 'Manager',
                'phone_called' => '9876543210',
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(CallOutcome::CallbackRequested, CallRecord::sole()->outcome);
    }

    public function test_appointment_set_without_contact_details_fails_validation(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateCallRecord::class)
            ->fillForm($this->baseFormData([
                'outcome' => CallOutcome::AppointmentSet->value,
                'appointment_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'contact_person_spoken_to' => null,
                'designation' => null,
                'phone_called' => null,
            ]))
            ->call('create')
            ->assertHasFormErrors(['contact_person_spoken_to', 'designation', 'phone_called']);

        $this->assertDatabaseCount('call_records', 0);
    }

    public function test_appointment_set_with_contact_details_succeeds(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateCallRecord::class)
            ->fillForm($this->baseFormData([
                'outcome' => CallOutcome::AppointmentSet->value,
                'appointment_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'contact_person_spoken_to' => 'Jane Doe',
                'designation' => 'Manager',
                'phone_called' => '9876543210',
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(CallOutcome::AppointmentSet, CallRecord::sole()->outcome);
    }

    #[DataProvider('mandatoryOutcomes')]
    public function test_a_mandatory_outcome_without_contact_details_fails_validation(CallOutcome $outcome, array $extra): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateCallRecord::class)
            ->fillForm($this->baseFormData(array_merge($extra, [
                'outcome' => $outcome->value,
                'contact_person_spoken_to' => null,
                'designation' => null,
                'phone_called' => null,
            ])))
            ->call('create')
            ->assertHasFormErrors(['contact_person_spoken_to', 'designation', 'phone_called']);

        $this->assertDatabaseCount('call_records', 0);
    }

    #[DataProvider('mandatoryOutcomes')]
    public function test_a_mandatory_outcome_with_contact_details_succeeds(CallOutcome $outcome, array $extra): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateCallRecord::class)
            ->fillForm($this->baseFormData(array_merge($extra, [
                'outcome' => $outcome->value,
                'contact_person_spoken_to' => 'Jane Doe',
                'designation' => 'Manager',
                'phone_called' => '9876543210',
            ])))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($outcome, CallRecord::sole()->outcome);
    }

    public static function mandatoryOutcomes(): array
    {
        return [
            'Callback Requested' => [CallOutcome::CallbackRequested, ['follow_up_at' => now()->addDay()->format('Y-m-d H:i:s')]],
            'Concerned Person Not Available' => [CallOutcome::ConcernedPersonNotAvailable, []],
            'Profile Requested' => [CallOutcome::ProfileRequested, ['profile_sent_status' => ProfileSentStatus::Pending->value]],
            'Appointment Set' => [CallOutcome::AppointmentSet, ['appointment_at' => now()->addDay()->format('Y-m-d H:i:s')]],
            'Requirement Identified' => [CallOutcome::RequirementIdentified, []],
            'No Current Requirement' => [CallOutcome::NoCurrentRequirement, []],
        ];
    }

    #[DataProvider('exemptOutcomes')]
    public function test_an_exempt_outcome_without_contact_details_still_succeeds(CallOutcome $outcome, array $extra): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateCallRecord::class)
            ->fillForm($this->baseFormData(array_merge($extra, [
                'outcome' => $outcome->value,
                'contact_person_spoken_to' => null,
                'designation' => null,
                'phone_called' => null,
            ])))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('call_records', 1);
    }

    public static function exemptOutcomes(): array
    {
        return [
            'No Answer' => [CallOutcome::NoAnswer, ['notes' => null]],
            'Switched Off' => [CallOutcome::SwitchedOff, ['notes' => null]],
            'Not Reachable' => [CallOutcome::NotReachable, ['notes' => null]],
            'Others' => [CallOutcome::Others, ['next_action' => CallNextAction::NoFurtherAction->value]],
        ];
    }

    /**
     * Defense in depth: the model guard must reject a
     * requires-contact-details outcome even when it bypasses the Filament
     * form entirely — mirrors CallRecordRequiresNotesTest's own model-guard
     * coverage.
     */
    public function test_model_guard_rejects_an_appointment_set_call_record_without_contact_details(): void
    {
        $this->expectException(\LogicException::class);

        $prospect = Prospect::factory()->create();

        CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'called_at' => now(),
            'outcome' => CallOutcome::AppointmentSet,
            'appointment_at' => now()->addDay(),
            'notes' => 'Agreed to a site visit.',
        ]);
    }

    public function test_model_guard_allows_a_switched_off_call_record_without_contact_details(): void
    {
        $prospect = Prospect::factory()->create();

        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'called_at' => now(),
            'outcome' => CallOutcome::SwitchedOff,
        ]);

        $this->assertSame(CallOutcome::SwitchedOff, $call->fresh()->outcome);
    }

    /**
     * Part 2 of this task: Contact Person, Designation, and Phone Called
     * must display on the Call's View page for an existing Call that has
     * this data (CallRecordResource has no dedicated infolist(), so
     * ViewRecord falls back to rendering formSchema() disabled — these
     * three fields are part of that shared schema and were already
     * rendering there; this test locks in that they keep showing under
     * their clarified labels).
     */
    public function test_view_page_displays_contact_details(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create();

        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'called_at' => now(),
            'outcome' => CallOutcome::AppointmentSet,
            'appointment_at' => now()->addDay(),
            'notes' => 'Site visit confirmed.',
            'contact_person_spoken_to' => 'Jane Q. Contact',
            'designation' => 'Procurement Head',
            'phone_called' => '+1-555-0100',
        ]);

        $this->actingAs($admin);

        // CallRecordResource has no dedicated infolist(), so ViewRecord
        // renders the shared formSchema() disabled — a disabled TextInput's
        // value is hydrated client-side by Alpine/Livewire from the
        // component's wire:snapshot state rather than a static HTML
        // `value=""` attribute, so this asserts against the FULL response
        // (stripInitialData: false) rather than the visible-text-only view
        // assertSee() normally checks — verified independently via a real
        // browser screenshot that these values do genuinely render.
        Livewire::test(ViewCallRecord::class, ['record' => $call->getRouteKey()])
            ->assertSee('Contact Person')
            ->assertSee('Designation')
            ->assertSee('Phone Called')
            ->assertSee('Jane Q. Contact', escape: true, stripInitialData: false)
            ->assertSee('Procurement Head', escape: true, stripInitialData: false)
            ->assertSee('+1-555-0100', escape: true, stripInitialData: false);
    }
}
