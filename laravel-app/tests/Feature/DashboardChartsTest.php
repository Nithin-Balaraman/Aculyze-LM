<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Enums\ProposalOutcome;
use App\Filament\Widgets\ConversionTrendChart;
use App\Filament\Widgets\LeadsByStageChart;
use App\Filament\Widgets\LeadsLostByStageChart;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Prospect;
use App\Models\User;
use Filament\Widgets\ChartWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

/**
 * Change Request Section 3 / "Decision 6" dashboard charts. Same
 * employeeId-scoping pattern verified for the pre-existing stats widgets in
 * DashboardScopingTest.
 *
 * getData() is invoked directly (bypassing the Livewire/Alpine chart.js
 * rendering pipeline, which only ever serializes this same array into the
 * page) since that's the one place the actual query logic lives.
 */
class DashboardChartsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function chartData(ChartWidget $widget): array
    {
        return (fn () => $this->getData())->call($widget);
    }

    private function makeLead(User $owner, LeadStage $stage): Lead
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);

        return Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'stage' => $stage,
            'temperature' => 'warm',
            'notes' => $stage === LeadStage::Validated ? 'Validated in test fixture.' : null,
        ]);
    }

    public function test_leads_by_stage_chart_counts_only_that_employees_leads_when_scoped(): void
    {
        $nithin = User::factory()->create();
        $kural = User::factory()->create();

        $this->makeLead($nithin, LeadStage::RequirementCollection);
        $this->makeLead($nithin, LeadStage::RequirementCollection);
        $this->makeLead($kural, LeadStage::RequirementCollection);

        $this->actingAs($nithin);

        $widget = new LeadsByStageChart;
        $widget->employeeId = $nithin->id;
        $data = $this->chartData($widget);

        $index = array_search(LeadStage::RequirementCollection->value, array_map(fn (LeadStage $s) => $s->value, LeadStage::cases()));

        $this->assertSame(2, $data['datasets'][0]['data'][$index]);
    }

    public function test_leads_by_stage_chart_aggregates_everyone_when_unscoped(): void
    {
        $admin = User::factory()->admin()->create();
        $nithin = User::factory()->create();
        $kural = User::factory()->create();

        $this->makeLead($nithin, LeadStage::RequirementCollection);
        $this->makeLead($kural, LeadStage::RequirementCollection);

        $this->actingAs($admin);

        $data = $this->chartData(new LeadsByStageChart);
        $index = array_search(LeadStage::RequirementCollection->value, array_map(fn (LeadStage $s) => $s->value, LeadStage::cases()));

        $this->assertSame(2, $data['datasets'][0]['data'][$index]);
    }

    public function test_leads_lost_by_stage_chart_buckets_by_the_stage_a_lead_was_lost_at_not_its_current_stage(): void
    {
        $admin = User::factory()->admin()->create();

        $lead = $this->makeLead($admin, LeadStage::DemoScheduledOrDone);
        $lead->markLost('Went quiet.');

        $this->makeLead($admin, LeadStage::DemoScheduledOrDone);

        $this->actingAs($admin);

        $data = $this->chartData(new LeadsLostByStageChart);
        $index = array_search(LeadStage::DemoScheduledOrDone->value, array_map(fn (LeadStage $s) => $s->value, LeadStage::cases()));

        // Only the lost Lead counts, not the still-active one at the same stage.
        $this->assertSame(1, $data['datasets'][0]['data'][$index]);
    }

    private function makeProposal(Prospect $prospect, ?ProposalOutcome $outcome, float $value): Proposal
    {
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'stage' => LeadStage::Validated,
            'temperature' => 'hot',
            'notes' => 'Validated in test fixture.',
        ]);

        return Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'stage' => 'sent',
            'outcome' => $outcome,
            'value' => $value,
        ]);
    }

    public function test_conversion_trend_chart_counts_proposals_won_within_the_selected_window(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create();

        $this->makeProposal($prospect, ProposalOutcome::Won, 1000);

        $wonLongAgo = $this->makeProposal($prospect, ProposalOutcome::Won, 500);
        Proposal::withoutEvents(fn () => $wonLongAgo->forceFill(['stage_changed_at' => Date::now()->subYear()])->save());

        $this->makeProposal($prospect, null, 0);

        $this->actingAs($admin);

        $widget = new ConversionTrendChart;
        $widget->filter = '30d';
        $data = $this->chartData($widget);

        $this->assertSame(1, $data['datasets'][0]['data']->sum());
    }
}
