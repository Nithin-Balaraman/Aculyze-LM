<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Models\Lead;
use App\Models\Prospect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

/**
 * AGENTS.md section 23: a Lead is stale at 30+ days without stage movement,
 * not stale below that, and terminal stages never count as stale.
 */
class StaleLeadTest extends TestCase
{
    use RefreshDatabase;

    private function leadStuckSince(int $daysAgo, LeadStage $stage = LeadStage::RequirementCollection): Lead
    {
        $prospect = Prospect::factory()->create();
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'stage' => $stage,
            'temperature' => 'warm',
            'notes' => $stage === LeadStage::Validated ? 'Validated in test fixture.' : null,
        ]);

        Lead::withoutEvents(fn () => $lead->forceFill(['stage_changed_at' => Date::now()->subDays($daysAgo)])->save());

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

    public function test_validated_lead_is_never_stale_even_after_30_days(): void
    {
        $lead = $this->leadStuckSince(60, LeadStage::Validated);

        $this->assertFalse($lead->isStale());
        $this->assertSame(0, Lead::query()->stale()->count());
    }
}
