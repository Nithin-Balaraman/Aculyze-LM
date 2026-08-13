<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Enums\ProposalOutcome;
use App\Enums\ProposalStage;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Prospect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

/**
 * AGENTS.md section 27: a Proposal is stale at 20+ days without stage
 * movement, not stale below that, and closed outcomes (Won/Lost) never
 * count as stale.
 */
class StaleProposalTest extends TestCase
{
    use RefreshDatabase;

    private function proposalStuckSince(int $daysAgo, ?ProposalOutcome $outcome = null): Proposal
    {
        $prospect = Prospect::factory()->create();
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'stage' => LeadStage::Validated,
            'temperature' => 'hot',
            'notes' => 'Validated in test fixture.',
        ]);
        $proposal = Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'stage' => ProposalStage::Sent,
            'outcome' => $outcome,
        ]);

        Proposal::withoutEvents(fn () => $proposal->forceFill(['stage_changed_at' => Date::now()->subDays($daysAgo)])->save());

        return $proposal->fresh();
    }

    public function test_proposal_stuck_for_20_days_is_stale(): void
    {
        $proposal = $this->proposalStuckSince(20);

        $this->assertTrue($proposal->isStale());
        $this->assertSame(1, Proposal::query()->stale()->count());
    }

    public function test_proposal_stuck_for_19_days_is_not_stale(): void
    {
        $proposal = $this->proposalStuckSince(19);

        $this->assertFalse($proposal->isStale());
        $this->assertSame(0, Proposal::query()->stale()->count());
    }

    public function test_won_proposal_is_never_stale(): void
    {
        $proposal = $this->proposalStuckSince(60, ProposalOutcome::Won);

        $this->assertFalse($proposal->isStale());
    }

    public function test_lost_proposal_is_never_stale(): void
    {
        $proposal = $this->proposalStuckSince(60, ProposalOutcome::Lost);

        $this->assertFalse($proposal->isStale());
    }

    public function test_hold_proposal_stale_behavior_follows_config_default(): void
    {
        // Default config: Hold is NOT terminal for staleness, so it keeps
        // aging like any open Proposal (AGENTS.md section 61, Question 6).
        config(['aculyze.hold_is_terminal_for_staleness' => false]);
        $proposal = $this->proposalStuckSince(25, ProposalOutcome::Hold);

        $this->assertTrue($proposal->isStale());
    }
}
