<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Enums\ProposalStage;
use App\Filament\Resources\CallRecordResource\Pages\ListCallRecords;
use App\Models\Appointment;
use App\Models\CallRecord;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Prospect;
use App\Models\Proposal;
use App\Models\User;
use App\Support\CallDownstreamChain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Flag-as-Incorrect: the counterpart to Correct Outcome, shown once a
 * Call's outcome has already created real downstream history
 * (CallRecord::deletionBlockers() non-empty) — Correct Outcome is hidden
 * in that case (see CorrectOutcomeTest's own coverage of that boundary),
 * and this records the Call for Saji's manual review instead of silently
 * rejecting the correction attempt.
 */
class FlagCallAsIncorrectTest extends TestCase
{
    use RefreshDatabase;

    private function makeCall(User $owner, CallOutcome $outcome, array $attributes = []): CallRecord
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);

        return CallRecord::create(array_merge([
            'prospect_id' => $prospect->id,
            'user_id' => $owner->id,
            'called_at' => now(),
            'outcome' => $outcome,
        ], $attributes));
    }

    public function test_flag_as_incorrect_is_hidden_when_there_is_no_downstream_history(): void
    {
        $employee = User::factory()->create();
        $call = $this->makeCall($employee, CallOutcome::NoAnswer);
        $this->actingAs($employee);

        Livewire::test(ListCallRecords::class)
            ->assertTableActionHidden('flagAsIncorrect', $call);
    }

    public function test_flag_as_incorrect_is_visible_once_real_downstream_history_exists(): void
    {
        $employee = User::factory()->create();
        $call = $this->makeCall($employee, CallOutcome::CallbackRequested, ['notes' => 'x']);
        $this->assertSame(1, FollowUp::count());
        $this->actingAs($employee);

        Livewire::test(ListCallRecords::class)
            ->assertTableActionVisible('flagAsIncorrect', $call);
    }

    public function test_flag_as_incorrect_records_the_flag_and_optional_reason(): void
    {
        $employee = User::factory()->create();
        $call = $this->makeCall($employee, CallOutcome::RequirementIdentified, ['notes' => 'Interested.']);
        $this->assertSame(1, Lead::count());
        $this->actingAs($employee);

        Livewire::test(ListCallRecords::class)
            ->callTableAction('flagAsIncorrect', $call, data: [
                'flag_reason' => 'The prospect never actually confirmed a requirement — outcome was recorded in error.',
            ])
            ->assertHasNoTableActionErrors();

        $call->refresh();
        $this->assertNotNull($call->flagged_incorrect_at);
        $this->assertSame(
            'The prospect never actually confirmed a requirement — outcome was recorded in error.',
            $call->flag_reason,
        );

        // Flagging is purely informational — nothing downstream is touched.
        $this->assertSame(1, Lead::count());
        $this->assertSame(CallOutcome::RequirementIdentified, $call->outcome);
    }

    public function test_flag_as_incorrect_reason_is_optional(): void
    {
        $employee = User::factory()->create();
        $call = $this->makeCall($employee, CallOutcome::AppointmentSet, ['notes' => 'Site visit set up.']);
        $this->actingAs($employee);

        Livewire::test(ListCallRecords::class)
            ->callTableAction('flagAsIncorrect', $call, data: ['flag_reason' => ''])
            ->assertHasNoTableActionErrors();

        $call->refresh();
        $this->assertNotNull($call->flagged_incorrect_at);
        $this->assertTrue(blank($call->flag_reason));
    }

    public function test_flag_as_incorrect_requires_authorization_like_correct_outcome(): void
    {
        $employee = User::factory()->create();
        $otherEmployee = User::factory()->create();
        $call = $this->makeCall($employee, CallOutcome::CallbackRequested, ['notes' => 'x']);

        $this->actingAs($otherEmployee);

        Livewire::test(ListCallRecords::class)
            ->assertTableActionHidden('flagAsIncorrect', $call);
    }

    public function test_flagging_again_updates_the_reason_and_re_stamps_the_timestamp(): void
    {
        $employee = User::factory()->create();
        $call = $this->makeCall($employee, CallOutcome::CallbackRequested, ['notes' => 'x']);
        $this->actingAs($employee);

        Livewire::test(ListCallRecords::class)
            ->callTableAction('flagAsIncorrect', $call, data: ['flag_reason' => 'First reason.']);
        $firstFlaggedAt = $call->fresh()->flagged_incorrect_at;

        $this->travel(1)->hour();

        Livewire::test(ListCallRecords::class)
            ->callTableAction('flagAsIncorrect', $call, data: ['flag_reason' => 'Updated reason.'])
            ->assertHasNoTableActionErrors();

        $call->refresh();
        $this->assertSame('Updated reason.', $call->flag_reason);
        $this->assertTrue($call->flagged_incorrect_at->greaterThan($firstFlaggedAt));
    }

    // --- Flagged review surface: the "Flagged" tab + downstream blocker info ---

    public function test_flagged_tab_shows_only_flagged_calls(): void
    {
        $employee = User::factory()->create();
        $flaggedCall = $this->makeCall($employee, CallOutcome::CallbackRequested, ['notes' => 'x']);
        $unflaggedCall = $this->makeCall($employee, CallOutcome::NoAnswer);
        $this->actingAs($employee);

        Livewire::test(ListCallRecords::class)
            ->callTableAction('flagAsIncorrect', $flaggedCall, data: ['flag_reason' => 'x']);

        Livewire::test(ListCallRecords::class)
            ->set('activeTab', 'flagged')
            ->assertCanSeeTableRecords([$flaggedCall->fresh()])
            ->assertCanNotSeeTableRecords([$unflaggedCall]);
    }

    /**
     * The core requirement of the flag review UI: a reviewer must be able
     * to see the downstream record's OWN deletionBlockers(), not just
     * that a downstream record exists — so they know whether a clean
     * two-step delete is still possible.
     */
    public function test_flag_details_action_surfaces_a_clean_downstream_record(): void
    {
        $employee = User::factory()->create();
        $call = $this->makeCall($employee, CallOutcome::CallbackRequested, ['notes' => 'x']);
        $this->actingAs($employee);

        Livewire::test(ListCallRecords::class)
            ->callTableAction('flagAsIncorrect', $call, data: ['flag_reason' => 'Wrong outcome.']);

        $call->refresh();
        $this->assertSame('Follow-Up', $call->downstreamRecordLabel());
        $this->assertSame([], array_filter($call->downstreamRecord()->deletionBlockers()));

        Livewire::test(ListCallRecords::class)
            ->assertTableActionVisible('viewFlagDetails', $call)
            ->mountTableAction('viewFlagDetails', $call)
            ->assertSee('Follow-Up')
            ->assertSee('Final link')
            ->assertSee('chain ends cleanly', false);
    }

    /**
     * The other real case: the downstream record has itself progressed
     * (here, a Lead that already has a Proposal — per AGENTS.md section
     * 59, a Proposal is never deletable once it has a commercial
     * Version, and Lead::deletionBlockers() already blocks the Lead
     * itself while any Proposal exists). The reviewer must see this,
     * not just "there's a Lead".
     */
    public function test_flag_details_action_surfaces_the_downstream_records_own_blockers(): void
    {
        $employee = User::factory()->create();
        $call = $this->makeCall($employee, CallOutcome::RequirementIdentified, ['notes' => 'Interested.']);
        $this->actingAs($employee);

        $lead = $call->fresh()->lead;
        $proposal = Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $lead->prospect_id,
            'assigned_to' => $lead->assigned_to,
            'created_by' => $lead->created_by,
            'stage' => ProposalStage::BeingPrepared,
        ]);
        // A real Proposal always has V1 created atomically (Phase 4A,
        // ProposalCreationService) — giving it a genuine commercial
        // Version here is what makes it PERMANENTLY undeletable (AGENTS.md
        // section 59), not merely existing. Without one, the Lead's own
        // "blocked by 1 Proposal" is entirely explained by this exact
        // chain link (about to be deleted right after it) and the whole
        // chain would in fact be cleanly deletable.
        \App\Models\ProposalVersion::factory()->create(['proposal_id' => $proposal->id]);

        Livewire::test(ListCallRecords::class)
            ->callTableAction('flagAsIncorrect', $call, data: ['flag_reason' => 'Wrong outcome.']);

        $call->refresh();
        $this->assertSame('Lead', $call->downstreamRecordLabel());
        $blockers = array_filter($call->downstreamRecord()->deletionBlockers());
        $this->assertSame(['Proposal' => 1], $blockers);
        $this->assertFalse(CallDownstreamChain::isClean($call));

        Livewire::test(ListCallRecords::class)
            ->mountTableAction('viewFlagDetails', $call)
            ->assertSee('Lead')
            ->assertSee('Proposal')
            ->assertSee('1 commercial Version', false)
            ->assertSee('blocked at Proposal', false);
    }

    public function test_view_flag_details_is_hidden_for_an_unflagged_call(): void
    {
        $employee = User::factory()->create();
        $call = $this->makeCall($employee, CallOutcome::CallbackRequested, ['notes' => 'x']);
        $this->actingAs($employee);

        Livewire::test(ListCallRecords::class)
            ->assertTableActionHidden('viewFlagDetails', $call);
    }

    // --- Full downstream chain: real multi-level chain + one-click "Delete Call + Downstream Chain" ---

    /**
     * The real "Call -> Follow-Up -> Appointment" chain this codebase
     * actually supports: completing a Pending Follow-Up
     * (FollowUpResource's "Completed" action / FollowUp::completeWithCall())
     * creates a brand-new Call Record (call_records.follow_up_id) that goes
     * through the exact same CallRecordObserver -> CallRoutingService path
     * any other logged call does — so an AppointmentSet outcome on THAT
     * call creates a real Appointment. The reviewer must see all three
     * hops (Follow-Up, the byproduct Call Record, then Appointment), with
     * the Appointment correctly identified as the true final link.
     */
    public function test_flag_details_action_surfaces_a_real_multi_level_chain(): void
    {
        $employee = User::factory()->create();
        $call = $this->makeCall($employee, CallOutcome::CallbackRequested, ['notes' => 'x']);
        $this->actingAs($employee);

        $followUp = $call->fresh()->followUp;
        $this->assertNotNull($followUp);

        $followUp->completeWithCall([
            'outcome' => CallOutcome::AppointmentSet,
            'notes' => 'Site visit confirmed.',
            'appointment_at' => now()->addDays(2),
        ]);
        $this->assertSame(1, Appointment::count());

        Livewire::test(ListCallRecords::class)
            ->callTableAction('flagAsIncorrect', $call, data: ['flag_reason' => 'Wrong outcome.']);

        $chain = CallDownstreamChain::walk($call->fresh());
        $this->assertCount(3, $chain);
        $this->assertSame(['Follow-Up', 'Call Record', 'Appointment'], array_column($chain, 'label'));
        $this->assertInstanceOf(Appointment::class, $chain[2]['record']);
        $this->assertTrue(CallDownstreamChain::isClean($call->fresh()));

        Livewire::test(ListCallRecords::class)
            ->mountTableAction('viewFlagDetails', $call)
            ->assertSee('Follow-Up')
            ->assertSee('Appointment')
            ->assertSee('Final link')
            ->assertSee('chain ends cleanly', false);
    }

    public function test_delete_call_and_chain_action_deletes_a_simple_one_level_chain_atomically(): void
    {
        $admin = User::factory()->admin()->create();
        $call = $this->makeCall($admin, CallOutcome::CallbackRequested, ['notes' => 'x']);
        $this->actingAs($admin);

        Livewire::test(ListCallRecords::class)
            ->callTableAction('flagAsIncorrect', $call, data: ['flag_reason' => 'Wrong outcome.']);

        $call->refresh();
        $this->assertTrue(CallDownstreamChain::isClean($call));

        Livewire::test(ListCallRecords::class)
            ->assertTableActionVisible('deleteCallAndChain', $call)
            ->callTableAction('deleteCallAndChain', $call);

        $this->assertSame(0, CallRecord::count());
        $this->assertSame(0, FollowUp::count());
    }

    /**
     * The multi-level case: the delete action must require EVERY level
     * (Follow-Up, the byproduct Call Record, and the Appointment) to be
     * clean, and deletes deepest-first inside a single transaction.
     */
    public function test_delete_call_and_chain_action_deletes_a_clean_multi_level_chain_atomically(): void
    {
        $admin = User::factory()->admin()->create();
        $call = $this->makeCall($admin, CallOutcome::CallbackRequested, ['notes' => 'x']);
        $this->actingAs($admin);

        $followUp = $call->fresh()->followUp;
        $followUp->completeWithCall([
            'outcome' => CallOutcome::AppointmentSet,
            'notes' => 'Site visit confirmed.',
            'appointment_at' => now()->addDays(2),
        ]);

        Livewire::test(ListCallRecords::class)
            ->callTableAction('flagAsIncorrect', $call, data: ['flag_reason' => 'Wrong outcome.']);

        $call->refresh();
        $this->assertTrue(CallDownstreamChain::isClean($call));

        Livewire::test(ListCallRecords::class)
            ->assertTableActionVisible('deleteCallAndChain', $call)
            ->callTableAction('deleteCallAndChain', $call);

        $this->assertSame(0, CallRecord::count());
        $this->assertSame(0, FollowUp::count());
        $this->assertSame(0, Appointment::count());
    }

    /**
     * The critical case: a chain blocked at the DEEPEST level (a Lead
     * that already has a Proposal, which per AGENTS.md section 59 can
     * never be deleted once it has a commercial Version) must refuse the
     * one-click delete — even though the immediate link (the Lead) would
     * look "clean" if only its own isolated blockers were checked without
     * discounting the very Proposal this chain is about to reach. No
     * record may be partially deleted.
     */
    public function test_delete_call_and_chain_action_refuses_when_the_deepest_link_is_blocked(): void
    {
        $admin = User::factory()->admin()->create();
        $call = $this->makeCall($admin, CallOutcome::RequirementIdentified, ['notes' => 'Interested.']);
        $this->actingAs($admin);

        $lead = $call->fresh()->lead;
        $proposal = Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $lead->prospect_id,
            'assigned_to' => $lead->assigned_to,
            'created_by' => $lead->created_by,
            'stage' => ProposalStage::BeingPrepared,
        ]);
        // A real Proposal always has V1 (Phase 4A) — this is what makes it
        // permanently undeletable, not merely existing (see the sibling
        // test above for the full reasoning).
        \App\Models\ProposalVersion::factory()->create(['proposal_id' => $proposal->id]);

        Livewire::test(ListCallRecords::class)
            ->callTableAction('flagAsIncorrect', $call, data: ['flag_reason' => 'Wrong outcome.']);

        $call->refresh();
        $this->assertFalse(CallDownstreamChain::isClean($call));

        Livewire::test(ListCallRecords::class)
            ->mountTableAction('deleteCallAndChain', $call)
            ->assertSee('cannot be fully deleted')
            ->assertSee('Blocked at Proposal', false);

        Livewire::test(ListCallRecords::class)
            ->callTableAction('deleteCallAndChain', $call);

        $this->assertSame(1, Lead::count());
        $this->assertSame(1, Proposal::count());
        $this->assertSame(1, CallRecord::count());
    }

    public function test_delete_call_and_chain_action_requires_admin_authorization(): void
    {
        $employee = User::factory()->create();
        $call = $this->makeCall($employee, CallOutcome::CallbackRequested, ['notes' => 'x']);
        $this->actingAs($employee);

        Livewire::test(ListCallRecords::class)
            ->callTableAction('flagAsIncorrect', $call, data: ['flag_reason' => 'Wrong outcome.']);

        Livewire::test(ListCallRecords::class)
            ->assertTableActionHidden('deleteCallAndChain', $call);
    }
}
