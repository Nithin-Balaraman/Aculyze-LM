<?php

namespace Tests\Feature;

use App\Enums\ProposalOutcome;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 4A-3.1 correction (locked Decision 18): stage_changed_at means the
 * time Proposal.stage genuinely changed — outcome changing by itself,
 * even the very first time, must not reset it. Verified safe against every
 * existing runtime writer of `outcome` (PipelineBoard's dropProposal()/
 * resolveCrossDropSource()) before being adopted, since both always set
 * `stage` in the same write whenever they set `outcome`.
 *
 * Also covers proposals.value's widened precision and
 * last_client_activity_at's schema shape (written only by the
 * not-yet-implemented ProposalClientResponseService, 4A-3.4 — nothing
 * backfills it here).
 */
class ProposalStageChangedAtTest extends TestCase
{
    use RefreshDatabase;

    private function proposal(User $actor, array $overrides = []): Proposal
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $actor->id, 'created_by' => $actor->id]);
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $actor->id,
            'created_by' => $actor->id,
            'stage' => 'validated',
            'temperature' => 'hot',
            'notes' => 'Fixture.',
        ]);

        return Proposal::create(array_merge([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $actor->id,
            'created_by' => $actor->id,
            'stage' => 'being_prepared',
        ], $overrides));
    }

    public function test_new_proposal_initializes_stage_changed_at(): void
    {
        $actor = User::factory()->create();
        $proposal = $this->proposal($actor);

        $this->assertNotNull($proposal->fresh()->stage_changed_at);
    }

    public function test_stage_change_resets_stage_changed_at(): void
    {
        $actor = User::factory()->create();
        $proposal = $this->proposal($actor);

        Proposal::withoutEvents(fn () => $proposal->forceFill(['stage_changed_at' => Date::now()->subDays(10)])->save());

        $proposal->update(['stage' => 'sent']);

        $this->assertTrue($proposal->fresh()->stage_changed_at->gt(Date::now()->subMinute()));
    }

    public function test_outcome_only_change_does_not_reset_stage_changed_at(): void
    {
        $actor = User::factory()->create();
        $proposal = $this->proposal($actor, ['stage' => 'sent']);

        Proposal::withoutEvents(fn () => $proposal->forceFill(['stage_changed_at' => Date::now()->subDays(10)])->save());
        // Re-read the DB-round-tripped value (the `timestamp` column has no
        // fractional-seconds precision) rather than comparing against the
        // original in-memory Carbon, which still carries microseconds.
        $oldTimestamp = $proposal->fresh()->stage_changed_at;

        // outcome changes, stage does not (mirrors a future More Time
        // client response: outcome -> Hold, stage stays Sent).
        $proposal->update(['outcome' => ProposalOutcome::Hold, 'notes' => 'On hold, awaiting budget.']);

        $this->assertTrue($oldTimestamp->equalTo($proposal->fresh()->stage_changed_at));
    }

    public function test_repeated_outcome_only_changes_never_reset_stage_changed_at(): void
    {
        $actor = User::factory()->create();
        $proposal = $this->proposal($actor, ['stage' => 'sent']);

        Proposal::withoutEvents(fn () => $proposal->forceFill(['stage_changed_at' => Date::now()->subDays(10)])->save());
        $oldTimestamp = $proposal->fresh()->stage_changed_at;

        $proposal->update(['outcome' => ProposalOutcome::Hold, 'notes' => 'First hold.']);
        $proposal->update(['outcome' => null]);
        $proposal->update(['outcome' => ProposalOutcome::Hold, 'notes' => 'Second hold.']);

        $this->assertTrue($oldTimestamp->equalTo($proposal->fresh()->stage_changed_at));
    }

    public function test_proposals_value_column_is_decimal_18_2(): void
    {
        $column = Schema::getColumnType('proposals', 'value');
        $this->assertSame('decimal', $column);

        $actor = User::factory()->create();
        $proposal = $this->proposal($actor, ['value' => '123456789012345.67']);

        $this->assertSame('123456789012345.67', $proposal->fresh()->value);
    }

    public function test_last_client_activity_at_is_nullable_and_not_backfilled(): void
    {
        $actor = User::factory()->create();
        $proposal = $this->proposal($actor);

        $this->assertNull($proposal->fresh()->last_client_activity_at);
    }

    public function test_a_proposal_with_no_stage_changed_at_is_never_stale_regardless_of_last_client_activity_at(): void
    {
        $actor = User::factory()->create();
        $proposal = $this->proposal($actor, ['stage' => 'sent']);

        Proposal::withoutEvents(fn () => $proposal->forceFill([
            'stage_changed_at' => null,
            'last_client_activity_at' => Date::now()->subDays(60),
        ])->save());

        $this->assertFalse($proposal->fresh()->isStale());
        $this->assertSame(0, Proposal::query()->stale()->where('id', $proposal->id)->count());
    }

    public function test_last_client_activity_at_can_extend_freshness_past_stage_changed_at(): void
    {
        $actor = User::factory()->create();
        $proposal = $this->proposal($actor, ['stage' => 'sent']);

        // stage_changed_at is old enough to be stale on its own, but a
        // later last_client_activity_at keeps it fresh.
        Proposal::withoutEvents(fn () => $proposal->forceFill([
            'stage_changed_at' => Date::now()->subDays(30),
            'last_client_activity_at' => Date::now()->subDays(1),
        ])->save());

        $fresh = $proposal->fresh();
        $this->assertFalse($fresh->isStale());
        $this->assertSame(0, Proposal::query()->stale()->where('id', $proposal->id)->count());
    }

    public function test_stale_scope_matches_is_stale_for_a_mixed_null_fixture_set(): void
    {
        $actor = User::factory()->create();

        $staleNoActivity = $this->proposal($actor, ['stage' => 'sent']);
        Proposal::withoutEvents(fn () => $staleNoActivity->forceFill(['stage_changed_at' => Date::now()->subDays(25)])->save());

        $freshDueToActivity = $this->proposal($actor, ['stage' => 'sent']);
        Proposal::withoutEvents(fn () => $freshDueToActivity->forceFill([
            'stage_changed_at' => Date::now()->subDays(25),
            'last_client_activity_at' => Date::now()->subDays(1),
        ])->save());

        $staleDespiteOldActivity = $this->proposal($actor, ['stage' => 'sent']);
        Proposal::withoutEvents(fn () => $staleDespiteOldActivity->forceFill([
            'stage_changed_at' => Date::now()->subDays(25),
            'last_client_activity_at' => Date::now()->subDays(24),
        ])->save());

        foreach ([$staleNoActivity, $freshDueToActivity, $staleDespiteOldActivity] as $proposal) {
            $this->assertSame(
                $proposal->fresh()->isStale(),
                Proposal::query()->stale()->where('id', $proposal->id)->exists(),
                "isStale() and scopeStale() disagree for Proposal #{$proposal->id}."
            );
        }

        $this->assertTrue($staleNoActivity->fresh()->isStale());
        $this->assertFalse($freshDueToActivity->fresh()->isStale());
        $this->assertTrue($staleDespiteOldActivity->fresh()->isStale());
    }
}
