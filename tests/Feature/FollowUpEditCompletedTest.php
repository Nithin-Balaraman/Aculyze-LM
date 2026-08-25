<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Enums\FollowUpStatus;
use App\Filament\Resources\FollowUpResource\Pages\CreateFollowUp;
use App\Filament\Resources\FollowUpResource\Pages\EditFollowUp;
use App\Models\CallRecord;
use App\Models\FollowUp;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Setting Status = Completed directly on the Follow-Up Edit/Create form
 * used to bypass the row-action "Completed" modal entirely — no Call
 * Record was ever created, so no outcome data was captured and
 * CallRoutingService never ran. This makes both entry points behave
 * identically: Outcome + Call Notes are required only when Status is set
 * to Completed (reactive on the form, and enforced server-side by
 * Livewire's validation regardless of JS), and a real Call Record is
 * created and routed exactly like the row-action modal already does —
 * including the outcome-driven Appointment At / Next Follow-Up At
 * sub-fields CallRecordResource's own form already uses, mirrored here via
 * FollowUpResource::resolveStatus()/outcomeRoutesToAppointment()/
 * outcomeRoutesToFollowUp().
 *
 * Neither `outcome`, `call_notes`, `appointment_at`, nor
 * `new_follow_up_at` persists on FollowUp itself — all four only ever end
 * up on the Call Record the completion creates.
 */
class FollowUpEditCompletedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The auto-routed Follow-Up a No Answer call creates starts with a
     * blank follow_up_at (CallRoutingService doesn't know a callback time
     * yet) — the model exempts that from its own mandatory-field guard, but
     * the Edit *form* still requires follow_up_at unconditionally, so it's
     * filled in here to keep these Completed-focused tests from tripping
     * over an unrelated field (already covered by its own dedicated test in
     * FollowUpMandatoryFieldsTest).
     */
    private function makeFollowUp(User $owner): FollowUp
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $owner->id,
            'called_at' => now(),
            'outcome' => CallOutcome::NoAnswer,
        ]);

        $followUp = $call->fresh()->followUp;
        $followUp->update(['follow_up_at' => now()->addDay()]);

        return $followUp->fresh();
    }

    public function test_edit_form_requires_outcome_when_completing(): void
    {
        $employee = User::factory()->create();
        $followUp = $this->makeFollowUp($employee);

        $this->actingAs($employee);

        Livewire::test(EditFollowUp::class, ['record' => $followUp->getRouteKey()])
            ->fillForm([
                'status' => FollowUpStatus::Completed->value,
                'outcome' => null,
                'call_notes' => 'Reached them this time.',
            ])
            ->call('save')
            ->assertHasFormErrors(['outcome' => 'required']);

        $this->assertSame(FollowUpStatus::Pending, $followUp->fresh()->status);
    }

    public function test_edit_form_requires_call_notes_when_completing(): void
    {
        $employee = User::factory()->create();
        $followUp = $this->makeFollowUp($employee);

        $this->actingAs($employee);

        Livewire::test(EditFollowUp::class, ['record' => $followUp->getRouteKey()])
            ->fillForm([
                'status' => FollowUpStatus::Completed->value,
                'outcome' => CallOutcome::RequirementIdentified->value,
                'call_notes' => null,
                'appointment_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ])
            ->call('save')
            ->assertHasFormErrors(['call_notes' => 'required']);

        $this->assertSame(FollowUpStatus::Pending, $followUp->fresh()->status);
    }

    public function test_edit_form_does_not_require_outcome_or_call_notes_when_not_completing(): void
    {
        $employee = User::factory()->create();
        $followUp = $this->makeFollowUp($employee);

        $this->actingAs($employee);

        Livewire::test(EditFollowUp::class, ['record' => $followUp->getRouteKey()])
            ->fillForm(['reason' => 'Updated reason, still pending'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Updated reason, still pending', $followUp->fresh()->reason);
    }

    public function test_completing_via_edit_form_creates_a_call_record_and_routes_it_normally(): void
    {
        $employee = User::factory()->create();
        $followUp = $this->makeFollowUp($employee);

        $this->actingAs($employee);

        $appointmentAt = now()->addDays(3)->startOfMinute();

        Livewire::test(EditFollowUp::class, ['record' => $followUp->getRouteKey()])
            ->fillForm([
                'status' => FollowUpStatus::Completed->value,
                'outcome' => CallOutcome::RequirementIdentified->value,
                'call_notes' => 'Spoke to the owner, ready to move forward.',
                'appointment_at' => $appointmentAt->format('Y-m-d H:i:s'),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $followUp->refresh();
        $this->assertSame(FollowUpStatus::Completed, $followUp->status);

        $this->assertSame(2, CallRecord::where('prospect_id', $followUp->prospect_id)->count());

        $newCall = CallRecord::where('prospect_id', $followUp->prospect_id)
            ->where('outcome', CallOutcome::RequirementIdentified)
            ->first();

        $this->assertNotNull($newCall);
        $this->assertSame('Spoke to the owner, ready to move forward.', $newCall->notes);
        $this->assertSame($followUp->id, $newCall->follow_up_id);
        $this->assertNotNull($newCall->appointment);
        $this->assertTrue($appointmentAt->equalTo($newCall->appointment->appointment_at));
        $this->assertNotNull($newCall->lead);
    }

    /**
     * Requirement Identified routes to a Lead too, but Appointment Set
     * routes only to an Appointment — Next Follow-Up At must stay hidden
     * either way (neither outcome routes to Follow-Up).
     */
    public function test_completing_with_appointment_set_creates_only_an_appointment_no_lead(): void
    {
        $employee = User::factory()->create();
        $followUp = $this->makeFollowUp($employee);

        $this->actingAs($employee);

        $appointmentAt = now()->addDays(2)->startOfMinute();

        Livewire::test(EditFollowUp::class, ['record' => $followUp->getRouteKey()])
            ->fillForm([
                'status' => FollowUpStatus::Completed->value,
                'outcome' => CallOutcome::AppointmentSet->value,
                'call_notes' => 'They agreed to a site visit.',
                'appointment_at' => $appointmentAt->format('Y-m-d H:i:s'),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $newCall = CallRecord::where('prospect_id', $followUp->prospect_id)
            ->where('outcome', CallOutcome::AppointmentSet)
            ->first();

        $this->assertNotNull($newCall);
        $this->assertNotNull($newCall->appointment);
        $this->assertTrue($appointmentAt->equalTo($newCall->appointment->appointment_at));
        $this->assertNull($newCall->lead);
    }

    /**
     * An outcome that itself routes back to Follow-Ups (e.g. the retry call
     * still went to No Answer) needs Next Follow-Up At, not Appointment At —
     * and creates a brand new, separate, Pending Follow-Up.
     */
    public function test_completing_with_a_follow_up_routing_outcome_requires_and_uses_next_follow_up_at(): void
    {
        $employee = User::factory()->create();
        $followUp = $this->makeFollowUp($employee);

        $this->actingAs($employee);

        Livewire::test(EditFollowUp::class, ['record' => $followUp->getRouteKey()])
            ->fillForm([
                'status' => FollowUpStatus::Completed->value,
                'outcome' => CallOutcome::SwitchedOff->value,
                'call_notes' => 'Still switched off, try again later.',
                'new_follow_up_at' => null,
            ])
            ->call('save')
            ->assertHasFormErrors(['new_follow_up_at' => 'required']);

        $nextFollowUpAt = now()->addWeek()->startOfMinute();

        Livewire::test(EditFollowUp::class, ['record' => $followUp->fresh()->getRouteKey()])
            ->fillForm([
                'status' => FollowUpStatus::Completed->value,
                'outcome' => CallOutcome::SwitchedOff->value,
                'call_notes' => 'Still switched off, try again later.',
                'new_follow_up_at' => $nextFollowUpAt->format('Y-m-d H:i:s'),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $newCall = CallRecord::where('prospect_id', $followUp->prospect_id)
            ->where('outcome', CallOutcome::SwitchedOff)
            ->first();

        $this->assertNotNull($newCall);
        $newFollowUp = FollowUp::where('call_record_id', $newCall->id)->first();
        $this->assertNotNull($newFollowUp);
        $this->assertNotSame($followUp->id, $newFollowUp->id);
        $this->assertSame(FollowUpStatus::Pending, $newFollowUp->status);
        $this->assertTrue($nextFollowUpAt->equalTo($newFollowUp->follow_up_at));
    }

    /**
     * Neither `outcome` nor `call_notes` is a FollowUp column, so re-opening
     * an already-Completed record's Edit page must pre-fill both (and
     * appointment_at, for an outcome that needed it) from its real
     * generatedCallRecord — otherwise an unrelated resave (e.g. fixing a
     * typo in `reason`) would fail validation over fields the user never
     * touched, and would create a duplicate Call Record if it didn't.
     */
    public function test_resaving_an_already_completed_follow_up_does_not_duplicate_the_call_record(): void
    {
        $employee = User::factory()->create();
        $followUp = $this->makeFollowUp($employee);

        $this->actingAs($employee);

        $appointmentAt = now()->addDays(3)->startOfMinute();

        Livewire::test(EditFollowUp::class, ['record' => $followUp->getRouteKey()])
            ->fillForm([
                'status' => FollowUpStatus::Completed->value,
                'outcome' => CallOutcome::RequirementIdentified->value,
                'call_notes' => 'Spoke to the owner, ready to move forward.',
                'appointment_at' => $appointmentAt->format('Y-m-d H:i:s'),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(2, CallRecord::where('prospect_id', $followUp->prospect_id)->count());

        Livewire::test(EditFollowUp::class, ['record' => $followUp->fresh()->getRouteKey()])
            ->assertFormSet([
                'outcome' => CallOutcome::RequirementIdentified->value,
                'call_notes' => 'Spoke to the owner, ready to move forward.',
                'appointment_at' => $appointmentAt->format('Y-m-d H:i'),
            ])
            ->fillForm(['reason' => 'Corrected reason text'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(2, CallRecord::where('prospect_id', $followUp->prospect_id)->count());
        $this->assertSame('Corrected reason text', $followUp->fresh()->reason);
    }

    public function test_create_form_requires_outcome_and_call_notes_when_creating_directly_as_completed(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create();

        $this->actingAs($admin);

        Livewire::test(CreateFollowUp::class)
            ->fillForm([
                'prospect_id' => $prospect->id,
                'follow_up_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'reason' => 'Manually logging a completed call',
                'status' => FollowUpStatus::Completed->value,
                'outcome' => null,
                'call_notes' => null,
            ])
            ->call('create')
            ->assertHasFormErrors(['outcome' => 'required', 'call_notes' => 'required']);

        $this->assertDatabaseCount('follow_ups', 0);
    }

    public function test_creating_directly_as_completed_creates_a_call_record_and_routes_it_normally(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create();

        $this->actingAs($admin);

        $appointmentAt = now()->addDays(4)->startOfMinute();

        Livewire::test(CreateFollowUp::class)
            ->fillForm([
                'prospect_id' => $prospect->id,
                'follow_up_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'reason' => 'Manually logging a completed call',
                'status' => FollowUpStatus::Completed->value,
                'outcome' => CallOutcome::RequirementIdentified->value,
                'call_notes' => 'Backfilled from a call taken outside the system.',
                'appointment_at' => $appointmentAt->format('Y-m-d H:i:s'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('follow_ups', 1);

        $followUp = FollowUp::first();
        $this->assertSame(FollowUpStatus::Completed, $followUp->status);

        $newCall = CallRecord::where('prospect_id', $prospect->id)->first();
        $this->assertNotNull($newCall);
        $this->assertSame(CallOutcome::RequirementIdentified, $newCall->outcome);
        $this->assertSame('Backfilled from a call taken outside the system.', $newCall->notes);
        $this->assertSame($followUp->id, $newCall->follow_up_id);
        $this->assertNotNull($newCall->lead);
        $this->assertNotNull($newCall->appointment);
        $this->assertTrue($appointmentAt->equalTo($newCall->appointment->appointment_at));
    }

    /**
     * Regression guard for the actual live-browser bug: a *live* Select
     * interaction (the user genuinely changing the dropdown, simulated here
     * via ->set() rather than a bulk ->fillForm()) hands the enum instance
     * back to Get closures, not the raw string — confirmed live, this
     * silently broke the Create form's reactive Outcome/Call Notes fields
     * entirely (they never appeared no matter what Status was picked) while
     * every fillForm()-based test above kept passing, since fillForm()
     * doesn't exercise the same code path. FollowUpResource::
     * resolveStatus() is the fix; this exercises it the way fillForm()
     * cannot.
     */
    public function test_live_status_selection_reactively_reveals_completion_fields_on_create(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $test = Livewire::test(CreateFollowUp::class);
        $this->assertStringNotContainsString('Call Outcome', $test->html());

        $test->set('data.status', FollowUpStatus::Completed->value);

        $this->assertStringContainsString('Call Outcome', $test->html());
        $this->assertStringContainsString('Call Notes', $test->html());
    }

    public function test_live_status_selection_reactively_reveals_completion_fields_on_edit(): void
    {
        $employee = User::factory()->create();
        $followUp = $this->makeFollowUp($employee);
        $this->actingAs($employee);

        $test = Livewire::test(EditFollowUp::class, ['record' => $followUp->getRouteKey()]);
        $this->assertStringNotContainsString('Call Outcome', $test->html());

        $test->set('data.status', FollowUpStatus::Completed->value);

        $this->assertStringContainsString('Call Outcome', $test->html());
        $this->assertStringContainsString('Call Notes', $test->html());
    }
}
