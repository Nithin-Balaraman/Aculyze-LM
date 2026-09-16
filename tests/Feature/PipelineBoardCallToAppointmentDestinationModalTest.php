<?php

namespace Tests\Feature;

use App\Enums\AppointmentMode;
use App\Enums\CallOutcome;
use App\Filament\Pages\PipelineBoard;
use App\Models\Appointment;
use App\Models\CallRecord;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pipeline Board V2 (Calls column, locked design section 4): the
 * destination-specific "Create Appointment" dialog has no outcome picker —
 * dropping a Call onto Appointments has exactly one deterministic meaning
 * (outcome forced to AppointmentSet, next_action forced to null — see
 * PipelineBoard::resolveCallOutcomeForDestination()) — and collects the new
 * mode/person_meeting/location fields plus "Additional Notes" (the
 * user-facing label for appointments.meeting_notes), all routed through the
 * existing CallRecord -> CallRecordObserver -> CallRoutingService ->
 * createAppointment() path, never a parallel direct Appointment::create().
 */
class PipelineBoardCallToAppointmentDestinationModalTest extends TestCase
{
    use RefreshDatabase;

    private function invokePerformCrossDrop(PipelineBoard $board, array $arguments, array $data = []): void
    {
        $method = new \ReflectionMethod($board, 'performCrossDrop');
        $method->setAccessible(true);
        $method->invoke($board, $arguments, $data);
    }

    private function makeCall(User $user, Prospect $prospect): CallRecord
    {
        return CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $user->id,
            'called_at' => now()->subDay(),
            'outcome' => CallOutcome::NoAnswer,
        ]);
    }

    /**
     * "Pre-fill Contact Person/Designation/Phone" enhancement request:
     * this modal has no such fields — locked design section 4's field list
     * is exactly Appointment Date & Time/Mode/Person Meeting/Location/
     * Additional Notes, and `appointment_person_meeting` is deliberately a
     * DIFFERENT concept from the Call's own contact_person_spoken_to (this
     * class's own docblock: "may differ from who was spoken to on this
     * call"). Guards against ever silently pre-filling Person Meeting from
     * contact_person_spoken_to, which would collapse that explicit
     * distinction.
     */
    public function test_the_live_modal_does_not_prefill_person_meeting_from_the_calls_contact_person(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $call = CallRecord::create([
                'prospect_id' => $prospect->id,
                'user_id' => $user->id,
                'called_at' => now()->subDay(),
                'outcome' => CallOutcome::NoAnswer,
                'contact_person_spoken_to' => 'Someone Else Entirely',
            ]);

            Livewire::test(PipelineBoard::class)
                ->mountAction('crossDrop', [
                    'sourceResource' => 'call',
                    'sourceId' => $call->id,
                    'destResource' => 'appointment',
                    'destStage' => 'appointment_made',
                ])
                ->assertActionDataSet(['appointment_person_meeting' => null]);
        });
    }

    public function test_the_resulting_appointment_receives_mode_person_meeting_location_and_notes(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $call = $this->makeCall($user, $prospect);

            $this->invokePerformCrossDrop(
                app(PipelineBoard::class),
                ['sourceResource' => 'call', 'sourceId' => $call->id, 'destResource' => 'appointment', 'destStage' => 'appointment_made'],
                [
                    'called_at' => now(),
                    'appointment_at' => now()->addDays(2),
                    'appointment_mode' => AppointmentMode::InPerson->value,
                    'appointment_person_meeting' => 'Jane Procurement',
                    'appointment_location' => 'Head Office, 3rd Floor',
                    'notes' => 'Client wants a full product walkthrough.',
                ],
            );

            $appointment = Appointment::query()->where('prospect_id', $prospect->id)->sole();
            $this->assertSame(AppointmentMode::InPerson, $appointment->mode);
            $this->assertSame('Jane Procurement', $appointment->person_meeting);
            $this->assertSame('Head Office, 3rd Floor', $appointment->location);
            $this->assertSame('Client wants a full product walkthrough.', $appointment->meeting_notes);

            $newCall = CallRecord::query()->where('id', '!=', $call->id)->sole();
            $this->assertSame(CallOutcome::AppointmentSet, $newCall->outcome);
            $this->assertNull($newCall->next_action);
        });
    }

    public function test_outcome_is_always_appointment_set_regardless_of_submitted_data(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $call = $this->makeCall($user, $prospect);

            // Simulates a raw/tampered request — the real dialog has no
            // outcome field to submit this from at all.
            $this->invokePerformCrossDrop(
                app(PipelineBoard::class),
                ['sourceResource' => 'call', 'sourceId' => $call->id, 'destResource' => 'appointment', 'destStage' => 'appointment_made'],
                [
                    'outcome' => CallOutcome::NoCurrentRequirement->value,
                    'called_at' => now(),
                    'appointment_at' => now()->addDays(2),
                    'appointment_mode' => AppointmentMode::Online->value,
                    'appointment_person_meeting' => 'Jane Procurement',
                    'notes' => 'Agreed to an online walkthrough.',
                ],
            );

            $newCall = CallRecord::query()->where('id', '!=', $call->id)->sole();
            $this->assertSame(CallOutcome::AppointmentSet, $newCall->outcome);
        });
    }

    /**
     * Unrelated Appointment creation paths must remain unaffected — the
     * legacy Others + CreateAppointment route (still exercised via the
     * normal Record New Call flow, never this destination-specific dialog)
     * creates an Appointment with the new fields simply left null, exactly
     * as before this change.
     */
    public function test_other_plus_create_appointment_via_the_normal_call_routing_path_leaves_new_fields_null(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

            CallRecord::create([
                'prospect_id' => $prospect->id,
                'user_id' => $user->id,
                'called_at' => now(),
                'outcome' => CallOutcome::Others,
                'next_action' => \App\Enums\CallNextAction::CreateAppointment,
                'notes' => 'Unusual situation, needs a site visit.',
                'appointment_at' => now()->addDays(3),
            ]);

            $appointment = Appointment::query()->where('prospect_id', $prospect->id)->sole();
            $this->assertNull($appointment->mode);
            $this->assertNull($appointment->person_meeting);
            $this->assertNull($appointment->location);
            $this->assertSame('Unusual situation, needs a site visit.', $appointment->meeting_notes);
        });
    }

    /**
     * End-to-end proof through the REAL mounted Filament Action — the
     * dialog's own form validation, not just the internal PHP method,
     * enforces the locked requiredness rules (section 4A): Mode, Person
     * Meeting, and Additional Notes are required in THIS modal; Location
     * is required only when Mode is In-Person.
     */
    public function test_the_live_modal_requires_mode_person_meeting_and_notes_but_not_location_unless_in_person(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $call = $this->makeCall($user, $prospect);

            Livewire::test(PipelineBoard::class)
                ->mountAction('crossDrop', [
                    'sourceResource' => 'call',
                    'sourceId' => $call->id,
                    'destResource' => 'appointment',
                    'destStage' => 'appointment_made',
                ])
                ->setActionData(['appointment_at' => now()->addDay()->format('Y-m-d H:i:s')])
                ->callMountedAction()
                ->assertHasActionErrors(['appointment_mode', 'appointment_person_meeting', 'notes']);

            Livewire::test(PipelineBoard::class)
                ->mountAction('crossDrop', [
                    'sourceResource' => 'call',
                    'sourceId' => $call->id,
                    'destResource' => 'appointment',
                    'destStage' => 'appointment_made',
                ])
                ->setActionData([
                    'appointment_at' => now()->addDay()->format('Y-m-d H:i:s'),
                    'appointment_mode' => AppointmentMode::Online->value,
                    'appointment_person_meeting' => 'Jane Procurement',
                    'notes' => 'Online walkthrough agreed.',
                ])
                ->callMountedAction()
                ->assertHasNoActionErrors();

            $onlineAppointment = Appointment::query()->where('prospect_id', $prospect->id)->sole();
            $this->assertNull($onlineAppointment->location);

            Livewire::test(PipelineBoard::class)
                ->mountAction('crossDrop', [
                    'sourceResource' => 'call',
                    'sourceId' => $call->id,
                    'destResource' => 'appointment',
                    'destStage' => 'appointment_made',
                ])
                ->setActionData([
                    'appointment_at' => now()->addDay()->format('Y-m-d H:i:s'),
                    'appointment_mode' => AppointmentMode::InPerson->value,
                    'appointment_person_meeting' => 'Jane Procurement',
                    'notes' => 'In-person visit agreed.',
                ])
                ->callMountedAction()
                ->assertHasActionErrors(['appointment_location']);
        });
    }
}
