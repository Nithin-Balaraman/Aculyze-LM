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
 * created and routed exactly like the row-action modal already does.
 *
 * Neither `outcome` nor `call_notes` persists on FollowUp itself — both
 * only ever end up on the Call Record the completion creates.
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

        Livewire::test(EditFollowUp::class, ['record' => $followUp->getRouteKey()])
            ->fillForm([
                'status' => FollowUpStatus::Completed->value,
                'outcome' => CallOutcome::RequirementIdentified->value,
                'call_notes' => 'Spoke to the owner, ready to move forward.',
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
        $this->assertNotNull($newCall->lead);
    }

    /**
     * Neither `outcome` nor `call_notes` is a FollowUp column, so re-opening
     * an already-Completed record's Edit page must pre-fill both from its
     * real generatedCallRecord — otherwise an unrelated resave (e.g. fixing
     * a typo in `reason`) would fail validation over fields the user never
     * touched, and would create a duplicate Call Record if it didn't.
     */
    public function test_resaving_an_already_completed_follow_up_does_not_duplicate_the_call_record(): void
    {
        $employee = User::factory()->create();
        $followUp = $this->makeFollowUp($employee);

        $this->actingAs($employee);

        Livewire::test(EditFollowUp::class, ['record' => $followUp->getRouteKey()])
            ->fillForm([
                'status' => FollowUpStatus::Completed->value,
                'outcome' => CallOutcome::RequirementIdentified->value,
                'call_notes' => 'Spoke to the owner, ready to move forward.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(2, CallRecord::where('prospect_id', $followUp->prospect_id)->count());

        Livewire::test(EditFollowUp::class, ['record' => $followUp->fresh()->getRouteKey()])
            ->assertFormSet([
                'outcome' => CallOutcome::RequirementIdentified->value,
                'call_notes' => 'Spoke to the owner, ready to move forward.',
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

        Livewire::test(CreateFollowUp::class)
            ->fillForm([
                'prospect_id' => $prospect->id,
                'follow_up_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'reason' => 'Manually logging a completed call',
                'status' => FollowUpStatus::Completed->value,
                'outcome' => CallOutcome::RequirementIdentified->value,
                'call_notes' => 'Backfilled from a call taken outside the system.',
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
    }
}
