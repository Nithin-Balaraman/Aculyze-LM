<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\Prospect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

/**
 * AGENTS.md section 23: a Lead is stale at 30+ days without movement, not
 * stale below that. Phase 3: migrated from legacy `stage`/`stage_changed_at`
 * to normalized `status`/`status_changed_at` (see Lead::isStale()/
 * scopeStale()) — only LeadStatus::NoCurrentProgression is exempt from
 * staleness now (LeadStatus::isTerminalForStaleness()), not every status a
 * legacy Validated stage happened to map to. A Lead sitting at
 * RequirementConfirmed (what legacy Validated normalizes to) for 30+ days
 * without becoming a Proposal is now correctly flagged stale — the old
 * "Validated is never stale" rule was a limitation of the legacy stage
 * model, not a deliberately preserved business exemption (this exact
 * design — only NoCurrentProgression exempt — was explicitly approved
 * during Phase 3 planning).
 */
class StaleLeadTest extends TestCase
{
    use RefreshDatabase;

    private function leadStuckSince(int $daysAgo, LeadStatus $status = LeadStatus::RequirementCollection): Lead
    {
        $prospect = Prospect::factory()->create();
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'status' => $status,
            'temperature' => 'warm',
            'notes' => $status === LeadStatus::ProposalRequired ? 'Ready for Proposal in test fixture.' : null,
        ]);

        Lead::withoutEvents(fn () => $lead->forceFill(['status_changed_at' => Date::now()->subDays($daysAgo)])->save());

        return $lead->fresh();
    }

    public function test_lead_stuck_for_30_days_is_stale(): void
    {
        $lead = $this->leadStuckSince(30);

        $this->assertTrue($lead->isStale());
        $this->assertSame(1, Lead::query()->stale()->count());
    }

    public function test_lead_stuck_for_more_than_30_days_is_stale(): void
    {
        $lead = $this->leadStuckSince(45);

        $this->assertTrue($lead->isStale());
    }

    public function test_lead_stuck_for_29_days_is_not_stale(): void
    {
        $lead = $this->leadStuckSince(29);

        $this->assertFalse($lead->isStale());
        $this->assertSame(0, Lead::query()->stale()->count());
    }

    public function test_freshly_created_lead_is_not_stale(): void
    {
        $lead = $this->leadStuckSince(0);

        $this->assertFalse($lead->isStale());
    }

    public function test_no_current_progression_lead_is_never_stale_even_after_30_days(): void
    {
        $lead = $this->leadStuckSince(60, LeadStatus::NoCurrentProgression);

        $this->assertFalse($lead->isStale());
        $this->assertSame(0, Lead::query()->stale()->count());
    }

    /**
     * A Lead ready for Proposal (status: Proposal Required — what legacy
     * Validated normalizes to) is NOT exempt from staleness under the
     * Phase 3 rule — if it sits without moving to an actual Proposal for
     * 30+ days, it is correctly flagged, unlike the old legacy-stage-only
     * behavior.
     */
    public function test_proposal_required_lead_stuck_for_30_days_is_now_correctly_stale(): void
    {
        $lead = $this->leadStuckSince(30, LeadStatus::ProposalRequired);

        $this->assertTrue($lead->isStale());
        $this->assertSame(1, Lead::query()->stale()->count());
    }
}
