<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Enums\FollowUpStatus;
use App\Enums\ProposalOutcome;
use App\Enums\ProposalStage;
use App\Filament\Widgets\KpiBand;
use App\Filament\Widgets\ProposalOutcomeChart;
use App\Models\CallRecord;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Prospect;
use App\Models\User;
use App\Support\DashboardPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * UX Fixes Batch Issue 6: the KpiBand widget replacing the old plain stat
 * cards, and the new ProposalOutcomeChart donut. Deltas must be real
 * period-over-period comparisons — never fabricated — so this locks in that
 * "no previous period" (All Time) means no delta shown at all, rather than
 * a made-up number.
 */
class DashboardKpiBandTest extends TestCase
{
    use RefreshDatabase;

    private function callsTile(array $tiles): array
    {
        return collect($tiles)->firstWhere('label', 'Calls');
    }

    public function test_previous_period_is_null_for_all_time(): void
    {
        [$prevFrom, $prevUntil] = DashboardPeriod::previous(['period' => 'all_time']);

        $this->assertNull($prevFrom);
        $this->assertNull($prevUntil);
    }

    public function test_previous_period_for_today_is_yesterday(): void
    {
        [$prevFrom, $prevUntil] = DashboardPeriod::previous(['period' => 'today']);

        $this->assertTrue($prevFrom->isYesterday());
        $this->assertTrue($prevUntil->isYesterday());
        $this->assertSame(Date::now()->startOfDay()->subSecond()->timestamp, $prevUntil->timestamp);
    }

    public function test_delta_is_omitted_rather_than_fabricated_when_there_is_no_previous_period(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create();
        CallRecord::create(['prospect_id' => $prospect->id, 'user_id' => $prospect->assigned_to, 'called_at' => now(), 'outcome' => CallOutcome::NoAnswer]);

        $this->actingAs($admin);

        $tiles = Livewire::test(KpiBand::class, ['filters' => ['period' => 'all_time']])->instance()->getTiles();

        $this->assertNull($this->callsTile($tiles)['delta']);
    }

    public function test_delta_reflects_a_real_period_over_period_change(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create();

        // 1 call yesterday (previous period), 3 calls today (current period).
        CallRecord::create(['prospect_id' => $prospect->id, 'user_id' => $prospect->assigned_to, 'called_at' => now()->subDay(), 'outcome' => CallOutcome::NoAnswer]);
        CallRecord::create(['prospect_id' => $prospect->id, 'user_id' => $prospect->assigned_to, 'called_at' => now(), 'outcome' => CallOutcome::NoAnswer]);
        CallRecord::create(['prospect_id' => $prospect->id, 'user_id' => $prospect->assigned_to, 'called_at' => now(), 'outcome' => CallOutcome::NoAnswer]);
        CallRecord::create(['prospect_id' => $prospect->id, 'user_id' => $prospect->assigned_to, 'called_at' => now(), 'outcome' => CallOutcome::NoAnswer]);

        $this->actingAs($admin);

        $tiles = Livewire::test(KpiBand::class, ['filters' => ['period' => 'today']])->instance()->getTiles();
        $calls = $this->callsTile($tiles);

        $this->assertSame(3, $calls['value']);
        // (3 - 1) / 1 * 100 = 200%
        $this->assertSame(200, $calls['delta']);
    }

    public function test_employee_scoped_tiles_exclude_another_employees_calls(): void
    {
        $nithin = User::factory()->create();
        $kural = User::factory()->create();
        $nithinProspect = Prospect::factory()->create(['assigned_to' => $nithin->id, 'created_by' => $nithin->id]);
        $kuralProspect = Prospect::factory()->create(['assigned_to' => $kural->id, 'created_by' => $kural->id]);

        CallRecord::create(['prospect_id' => $nithinProspect->id, 'user_id' => $nithin->id, 'called_at' => now(), 'outcome' => CallOutcome::NoAnswer]);
        CallRecord::create(['prospect_id' => $kuralProspect->id, 'user_id' => $kural->id, 'called_at' => now(), 'outcome' => CallOutcome::NoAnswer]);

        $this->actingAs($nithin);

        $tiles = Livewire::test(KpiBand::class, ['employeeId' => $nithin->id])->instance()->getTiles();

        $this->assertSame(1, $this->callsTile($tiles)['value']);
    }

    public function test_leads_tile_context_shows_hot_count_only_when_present(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create();
        Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'stage' => 'requirement_collection',
            'temperature' => 'hot',
        ]);

        $this->actingAs($admin);

        $tiles = Livewire::test(KpiBand::class)->instance()->getTiles();
        $leadsTile = collect($tiles)->firstWhere('label', 'Leads');

        $this->assertSame('1 hot', $leadsTile['context']);
    }

    public function test_sparkline_places_todays_activity_in_the_last_bucket(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create();
        CallRecord::create(['prospect_id' => $prospect->id, 'user_id' => $prospect->assigned_to, 'called_at' => now(), 'outcome' => CallOutcome::NoAnswer]);

        $this->actingAs($admin);

        $tiles = Livewire::test(KpiBand::class)->instance()->getTiles();
        $sparkline = $this->callsTile($tiles)['sparkline'];

        $this->assertCount(7, $sparkline);
        $this->assertSame(1, $sparkline[6]);
        $this->assertSame(0, array_sum(array_slice($sparkline, 0, 6)));
    }

    public function test_follow_ups_tile_reports_pending_and_completed_as_separate_counts(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create();

        FollowUp::create(['prospect_id' => $prospect->id, 'user_id' => $prospect->assigned_to, 'follow_up_at' => now()->addDay(), 'reason' => 'Callback', 'status' => FollowUpStatus::Pending]);
        FollowUp::create(['prospect_id' => $prospect->id, 'user_id' => $prospect->assigned_to, 'follow_up_at' => now()->addDay(), 'reason' => 'Callback', 'status' => FollowUpStatus::Pending]);
        FollowUp::create(['prospect_id' => $prospect->id, 'user_id' => $prospect->assigned_to, 'follow_up_at' => now()->addDay(), 'reason' => 'Callback', 'status' => FollowUpStatus::Completed]);
        FollowUp::create(['prospect_id' => $prospect->id, 'user_id' => $prospect->assigned_to, 'follow_up_at' => now()->addDay(), 'reason' => 'Callback', 'status' => FollowUpStatus::Cancelled]);

        $this->actingAs($admin);

        $followUpsTile = Livewire::test(KpiBand::class)->instance()->getFollowUpsTile();

        $this->assertSame(2, $followUpsTile['pending']);
        $this->assertSame(1, $followUpsTile['completed']);
    }

    public function test_follow_ups_tile_sparklines_place_todays_activity_in_the_last_bucket_and_dont_mix_statuses(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create();

        FollowUp::create(['prospect_id' => $prospect->id, 'user_id' => $prospect->assigned_to, 'follow_up_at' => now()->addDay(), 'reason' => 'Callback', 'status' => FollowUpStatus::Pending]);
        FollowUp::create(['prospect_id' => $prospect->id, 'user_id' => $prospect->assigned_to, 'follow_up_at' => now()->addDay(), 'reason' => 'Callback', 'status' => FollowUpStatus::Completed]);
        FollowUp::create(['prospect_id' => $prospect->id, 'user_id' => $prospect->assigned_to, 'follow_up_at' => now()->addDay(), 'reason' => 'Callback', 'status' => FollowUpStatus::Completed]);

        $this->actingAs($admin);

        $followUpsTile = Livewire::test(KpiBand::class)->instance()->getFollowUpsTile();

        $this->assertCount(7, $followUpsTile['pendingSparkline']);
        $this->assertSame(1, $followUpsTile['pendingSparkline'][6]);
        $this->assertSame(0, array_sum(array_slice($followUpsTile['pendingSparkline'], 0, 6)));

        $this->assertCount(7, $followUpsTile['completedSparkline']);
        $this->assertSame(2, $followUpsTile['completedSparkline'][6]);
        $this->assertSame(0, array_sum(array_slice($followUpsTile['completedSparkline'], 0, 6)));
    }

    public function test_follow_ups_tile_respects_employee_scoping(): void
    {
        $nithin = User::factory()->create();
        $kural = User::factory()->create();
        $nithinProspect = Prospect::factory()->create(['assigned_to' => $nithin->id, 'created_by' => $nithin->id]);
        $kuralProspect = Prospect::factory()->create(['assigned_to' => $kural->id, 'created_by' => $kural->id]);

        FollowUp::create(['prospect_id' => $nithinProspect->id, 'user_id' => $nithin->id, 'follow_up_at' => now()->addDay(), 'reason' => 'Callback', 'status' => FollowUpStatus::Pending]);
        FollowUp::create(['prospect_id' => $kuralProspect->id, 'user_id' => $kural->id, 'follow_up_at' => now()->addDay(), 'reason' => 'Callback', 'status' => FollowUpStatus::Pending]);
        FollowUp::create(['prospect_id' => $kuralProspect->id, 'user_id' => $kural->id, 'follow_up_at' => now()->addDay(), 'reason' => 'Callback', 'status' => FollowUpStatus::Pending]);

        $this->actingAs($nithin);

        $followUpsTile = Livewire::test(KpiBand::class, ['employeeId' => $nithin->id])->instance()->getFollowUpsTile();

        $this->assertSame(1, $followUpsTile['pending']);
    }

    public function test_follow_ups_tile_respects_the_selected_period(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create();

        $yesterday = FollowUp::create(['prospect_id' => $prospect->id, 'user_id' => $prospect->assigned_to, 'follow_up_at' => now()->addDay(), 'reason' => 'Callback', 'status' => FollowUpStatus::Pending]);
        $yesterday->forceFill(['created_at' => now()->subDay()])->saveQuietly();

        FollowUp::create(['prospect_id' => $prospect->id, 'user_id' => $prospect->assigned_to, 'follow_up_at' => now()->addDay(), 'reason' => 'Callback', 'status' => FollowUpStatus::Pending]);

        $this->actingAs($admin);

        $followUpsTile = Livewire::test(KpiBand::class, ['filters' => ['period' => 'today']])->instance()->getFollowUpsTile();

        $this->assertSame(1, $followUpsTile['pending']);
    }

    public function test_proposal_outcome_chart_respects_employee_scoping(): void
    {
        $nithin = User::factory()->create();
        $kural = User::factory()->create();
        $nithinProspect = Prospect::factory()->create(['assigned_to' => $nithin->id, 'created_by' => $nithin->id]);
        $kuralProspect = Prospect::factory()->create(['assigned_to' => $kural->id, 'created_by' => $kural->id]);

        $nithinLead = Lead::create(['prospect_id' => $nithinProspect->id, 'assigned_to' => $nithin->id, 'created_by' => $nithin->id, 'stage' => 'validated', 'temperature' => 'hot', 'notes' => 'Validated in test fixture.']);
        $kuralLead = Lead::create(['prospect_id' => $kuralProspect->id, 'assigned_to' => $kural->id, 'created_by' => $kural->id, 'stage' => 'validated', 'temperature' => 'hot', 'notes' => 'Validated in test fixture.']);

        Proposal::create(['lead_id' => $nithinLead->id, 'prospect_id' => $nithinProspect->id, 'assigned_to' => $nithin->id, 'created_by' => $nithin->id, 'stage' => ProposalStage::Sent, 'outcome' => ProposalOutcome::Won]);
        Proposal::create(['lead_id' => $kuralLead->id, 'prospect_id' => $kuralProspect->id, 'assigned_to' => $kural->id, 'created_by' => $kural->id, 'stage' => ProposalStage::Sent, 'outcome' => ProposalOutcome::Lost]);

        $this->actingAs($nithin);

        $widget = new ProposalOutcomeChart;
        $widget->employeeId = $nithin->id;
        $data = (fn () => $this->getData())->call($widget);

        // labels order: In Progress, Won, Hold, Lost
        $this->assertSame([0, 1, 0, 0], $data['datasets'][0]['data']);
    }
}
