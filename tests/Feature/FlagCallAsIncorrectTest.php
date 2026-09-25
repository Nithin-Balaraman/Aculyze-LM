<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Enums\ProposalStage;
use App\Filament\Resources\CallRecordResource\Pages\ListCallRecords;
use App\Models\CallRecord;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Prospect;
use App\Models\Proposal;
use App\Models\User;
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
            ->assertSee('clean two-step delete would work today', false);
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
        Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $lead->prospect_id,
            'assigned_to' => $lead->assigned_to,
            'created_by' => $lead->created_by,
            'stage' => ProposalStage::BeingPrepared,
        ]);

        Livewire::test(ListCallRecords::class)
            ->callTableAction('flagAsIncorrect', $call, data: ['flag_reason' => 'Wrong outcome.']);

        $call->refresh();
        $this->assertSame('Lead', $call->downstreamRecordLabel());
        $blockers = array_filter($call->downstreamRecord()->deletionBlockers());
        $this->assertSame(['Proposal' => 1], $blockers);

        Livewire::test(ListCallRecords::class)
            ->mountTableAction('viewFlagDetails', $call)
            ->assertSee('Lead')
            ->assertSee('1 Proposal');
    }

    public function test_view_flag_details_is_hidden_for_an_unflagged_call(): void
    {
        $employee = User::factory()->create();
        $call = $this->makeCall($employee, CallOutcome::CallbackRequested, ['notes' => 'x']);
        $this->actingAs($employee);

        Livewire::test(ListCallRecords::class)
            ->assertTableActionHidden('viewFlagDetails', $call);
    }
}
