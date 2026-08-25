<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Enums\LeadTemperature;
use App\Enums\ProposalOutcome;
use App\Enums\ProposalStage;
use App\Filament\Pages\MainDashboard;
use App\Filament\Pages\MyDashboard;
use App\Filament\Widgets\ChartDetailModal;
use App\Filament\Widgets\ConversionTrendChart;
use App\Filament\Widgets\GrowthTrendChart;
use App\Filament\Widgets\LeadsByStageChart;
use App\Filament\Widgets\LeadsByTemperatureChart;
use App\Filament\Widgets\LeadsLostByStageChart;
use App\Filament\Widgets\ProposalOutcomeChart;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Chart consistency fixes + click-to-detail (styling/interaction and
 * data-shape work, not content/layout changes to the underlying business
 * data).
 *
 * Root cause of the two originally-reported symptoms, confirmed live in a
 * real browser (not just from source): Filament's own chart Alpine
 * component (vendor/filament/widgets/resources/js/components/chart.js)
 * unconditionally injects an x/y scales config into every chart type,
 * regardless of whether that type actually has axes — for a doughnut,
 * this rendered a spurious numeric 0/1/2/3 axis nobody asked for.
 * Confirmed this affected every doughnut in the app (Proposal Outcomes
 * *and* Leads by Temperature), not just the one originally reported.
 * Separately, "Leads by Stage"'s rotated/cramped labels were Chart.js's
 * own default auto-rotation for a bar chart too narrow for its category
 * labels — reproduced live at a ~320px width (what a 3-column layout
 * gives each card) — fixed by flipping to a horizontal orientation
 * (indexAxis: 'y') rather than fighting the rotation.
 *
 * Click-to-detail: every chart in App\Filament\Widgets\Concerns\
 * HasClickableChartDetail dispatches a `chart-detail-open` browser event
 * on click, carrying its own chartKey/employeeId/bounding-rect;
 * App\Filament\Widgets\ChartDetailModal (registered once per dashboard)
 * listens for it via #[On('chart-detail-open')] and computes the actual
 * detail content. Two real, non-obvious bugs surfaced and fixed during
 * live verification, both worth a permanent regression guard:
 * - Wrapping <x-filament-widgets::widget> in an extra <div> (for the
 *   click handler) silently broke every full-width widget's columnSpan,
 *   since Filament's own grid-column styling lives on that component's
 *   own root element, not an ancestor of it — fixed by putting the click
 *   attributes directly on the component tag instead.
 * - Every Filament widget defaults to lazy-loaded (fetched after the
 *   initial page load, once visible) — a hidden modal never becomes
 *   visible, so it never lazy-loaded at all, and its whole DOM (as well
 *   as its Livewire listener) simply never existed until
 *   ChartDetailModal explicitly opted out via `$isLazy = false`.
 *
 * The actual animation/interaction (the zoom transition itself, hover
 * states, whether it *feels* smooth) can't be exercised by a PHP test
 * harness — this file only guards the plain PHP-level facts: chart
 * option/data-shape correctness, dashboard column counts, and
 * ChartDetailModal's computed detail content per chart key. All of the
 * above was additionally verified live in a real headless-Chromium
 * session: the doughnut axis bug is gone, the bar charts render
 * horizontally with side-running labels, both dashboards show 3 columns,
 * and clicking each of the 6 chart types opens the modal with the
 * expected content, all with zero console/page errors.
 */
class DashboardChartsTest extends TestCase
{
    use RefreshDatabase;

    public function test_proposal_outcome_chart_disables_the_spurious_axis(): void
    {
        $widget = new ProposalOutcomeChart;
        $options = (fn () => $this->getOptions())->call($widget);

        $this->assertFalse($options['scales']['x']['display']);
        $this->assertFalse($options['scales']['y']['display']);
    }

    public function test_leads_by_temperature_chart_disables_the_spurious_axis(): void
    {
        $widget = new LeadsByTemperatureChart;
        $options = (fn () => $this->getOptions())->call($widget);

        $this->assertFalse($options['scales']['x']['display']);
        $this->assertFalse($options['scales']['y']['display']);
    }

    public function test_leads_by_stage_chart_is_horizontal_with_whole_number_ticks(): void
    {
        $widget = new LeadsByStageChart;
        $options = (fn () => $this->getOptions())->call($widget);

        $this->assertSame('y', $options['indexAxis']);
        $this->assertSame(1, $options['scales']['x']['ticks']['stepSize']);
    }

    public function test_leads_lost_by_stage_chart_is_horizontal_with_whole_number_ticks(): void
    {
        $widget = new LeadsLostByStageChart;
        $options = (fn () => $this->getOptions())->call($widget);

        $this->assertSame('y', $options['indexAxis']);
        $this->assertSame(1, $options['scales']['x']['ticks']['stepSize']);
    }

    public function test_trend_line_charts_have_whole_number_y_ticks(): void
    {
        $growth = (fn () => $this->getOptions())->call(new GrowthTrendChart);
        $conversion = (fn () => $this->getOptions())->call(new ConversionTrendChart);

        $this->assertSame(1, $growth['scales']['y']['ticks']['stepSize']);
        $this->assertSame(1, $conversion['scales']['y']['ticks']['stepSize']);
    }

    /**
     * @return array<string, array{0: object, 1: string}>
     */
    public static function chartKeyProvider(): array
    {
        return [
            'proposal outcomes' => [ProposalOutcomeChart::class, 'proposal-outcomes'],
            'leads by temperature' => [LeadsByTemperatureChart::class, 'leads-by-temperature'],
            'leads by stage' => [LeadsByStageChart::class, 'leads-by-stage'],
            'leads lost by stage' => [LeadsLostByStageChart::class, 'leads-lost-by-stage'],
            'conversion trend' => [ConversionTrendChart::class, 'conversion-trend'],
            'growth trend' => [GrowthTrendChart::class, 'growth-trend'],
        ];
    }

    #[DataProvider('chartKeyProvider')]
    public function test_each_clickable_chart_reports_its_own_key(string $class, string $expectedKey): void
    {
        $widget = new $class;
        $key = (fn () => $this->getChartKey())->call($widget);

        $this->assertSame($expectedKey, $key);
    }

    public function test_both_dashboards_use_a_three_column_grid(): void
    {
        $this->assertSame(3, (new MainDashboard)->getColumns());
        $this->assertSame(3, (new MyDashboard)->getColumns());
    }

    /**
     * Regression guard for the lazy-loading bug: every Filament widget
     * defaults to lazy (Filament\Support\Concerns\CanBeLazy), which is
     * fine for a widget that's visibly on the page, but fatal for a
     * hidden modal, which never scrolls into view and so never
     * lazy-loads at all — confirmed live before this was set.
     */
    public function test_the_chart_detail_modal_is_not_lazy(): void
    {
        $this->assertFalse(ChartDetailModal::isLazy());
    }

    public function test_the_chart_detail_modal_opens_and_closes(): void
    {
        Livewire::test(ChartDetailModal::class)
            ->assertSet('isOpen', false)
            ->call('open', 'proposal-outcomes', null)
            ->assertSet('isOpen', true)
            ->assertSet('chartKey', 'proposal-outcomes')
            ->call('close')
            ->assertSet('isOpen', false);
    }

    public function test_proposal_outcomes_detail_shows_counts_percentages_and_total_value(): void
    {
        // A Proposal belongs to exactly one Lead (proposals.lead_id is
        // unique) — each needs its own Lead/Prospect, not shared.
        $user = User::factory()->create();

        $makeProposal = function (ProposalOutcome $outcome, float $value) use ($user) {
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $lead = Lead::create(['prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id, 'stage' => LeadStage::RequirementCollection, 'temperature' => LeadTemperature::Warm, 'notes' => 'x']);

            return Proposal::create(['lead_id' => $lead->id, 'prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id, 'stage' => ProposalStage::Sent, 'outcome' => $outcome, 'value' => $value, 'notes' => 'x']);
        };

        $makeProposal(ProposalOutcome::Won, 500);
        $makeProposal(ProposalOutcome::Won, 1500);
        $makeProposal(ProposalOutcome::Lost, 200);

        $this->actingAs($user);

        $detail = Livewire::test(ChartDetailModal::class)
            ->call('open', 'proposal-outcomes', null)
            ->instance()
            ->getDetail();

        $won = collect($detail['breakdown'])->firstWhere('label', 'Won');
        $lost = collect($detail['breakdown'])->firstWhere('label', 'Lost');

        $this->assertSame(2, $won['count']);
        $this->assertSame(66.7, $won['percentage']);
        $this->assertStringContainsString('$2,000.00', $won['meta']);

        $this->assertSame(1, $lost['count']);
        $this->assertStringContainsString('$200.00', $lost['meta']);

        $this->assertSame('doughnut', $detail['type']);
        $this->assertFalse($detail['chartOptions']['scales']['x']['display']);
    }

    public function test_proposal_outcomes_detail_respects_employee_scoping(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $ownProspect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);
        $otherProspect = Prospect::factory()->create(['assigned_to' => $other->id, 'created_by' => $other->id]);

        $ownLead = Lead::create(['prospect_id' => $ownProspect->id, 'assigned_to' => $owner->id, 'created_by' => $owner->id, 'stage' => LeadStage::RequirementCollection, 'temperature' => LeadTemperature::Warm, 'notes' => 'x']);
        $otherLead = Lead::create(['prospect_id' => $otherProspect->id, 'assigned_to' => $other->id, 'created_by' => $other->id, 'stage' => LeadStage::RequirementCollection, 'temperature' => LeadTemperature::Warm, 'notes' => 'x']);

        Proposal::create(['lead_id' => $ownLead->id, 'prospect_id' => $ownProspect->id, 'assigned_to' => $owner->id, 'created_by' => $owner->id, 'stage' => ProposalStage::Sent, 'outcome' => ProposalOutcome::Won, 'value' => 500, 'notes' => 'x']);
        Proposal::create(['lead_id' => $otherLead->id, 'prospect_id' => $otherProspect->id, 'assigned_to' => $other->id, 'created_by' => $other->id, 'stage' => ProposalStage::Sent, 'outcome' => ProposalOutcome::Won, 'value' => 999, 'notes' => 'x']);

        $this->actingAs($owner);

        $detail = Livewire::test(ChartDetailModal::class)
            ->call('open', 'proposal-outcomes', $owner->id)
            ->instance()
            ->getDetail();

        $won = collect($detail['breakdown'])->firstWhere('label', 'Won');

        $this->assertSame(1, $won['count']);
        $this->assertStringContainsString('$500.00', $won['meta']);
    }

    public function test_leads_by_temperature_detail_lists_hot_leads(): void
    {
        $user = User::factory()->create();
        $hotProspect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id, 'company_name' => 'Hot Co']);
        $coldProspect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id, 'company_name' => 'Cold Co']);

        Lead::create(['prospect_id' => $hotProspect->id, 'assigned_to' => $user->id, 'created_by' => $user->id, 'stage' => LeadStage::RequirementCollection, 'temperature' => LeadTemperature::Hot, 'notes' => 'x']);
        Lead::create(['prospect_id' => $coldProspect->id, 'assigned_to' => $user->id, 'created_by' => $user->id, 'stage' => LeadStage::RequirementCollection, 'temperature' => LeadTemperature::Cold, 'notes' => 'x']);

        $this->actingAs($user);

        $detail = Livewire::test(ChartDetailModal::class)
            ->call('open', 'leads-by-temperature', null)
            ->instance()
            ->getDetail();

        $hot = collect($detail['breakdown'])->firstWhere('label', 'Hot');
        $this->assertSame(1, $hot['count']);
        $this->assertSame(50.0, $hot['percentage']);

        $this->assertCount(1, $detail['listItems']);
        $this->assertSame('Hot Co', $detail['listItems'][0]['label']);
    }

    public function test_leads_by_stage_detail_includes_a_per_stage_trend(): void
    {
        $user = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
        Lead::create(['prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id, 'stage' => LeadStage::RequirementCollection, 'temperature' => LeadTemperature::Warm, 'notes' => 'x']);

        $this->actingAs($user);

        $detail = Livewire::test(ChartDetailModal::class)
            ->call('open', 'leads-by-stage', null)
            ->instance()
            ->getDetail();

        $this->assertSame('bar', $detail['type']);
        $this->assertSame('y', $detail['chartOptions']['indexAxis']);
        $this->assertCount(3, $detail['trend']['datasets']);
        $this->assertCount(12, $detail['trend']['labels']);
    }

    public function test_growth_trend_detail_has_a_per_period_table(): void
    {
        $detail = Livewire::test(ChartDetailModal::class)
            ->call('open', 'growth-trend', null)
            ->instance()
            ->getDetail();

        $this->assertCount(12, $detail['table']);
        $this->assertArrayHasKey('leadsCreated', $detail['table'][0]);
        $this->assertArrayHasKey('proposalsCreated', $detail['table'][0]);
    }

    /**
     * Bug fix: a doughnut (or any chart) whose datasets sum to zero can't
     * compute any arc/bar angles (division by zero), so Chart.js silently
     * draws nothing — confirmed live, "Proposal Outcomes" with zero
     * matching proposals rendered an empty canvas with only the legend
     * visible, easily misread as a rendering bug rather than a data one.
     * Same "No data yet" convention kpi-sparkline.blade.php already uses
     * for the same underlying reason.
     */
    public function test_chart_with_zero_data_shows_a_no_data_placeholder(): void
    {
        // No Proposals created at all — ProposalOutcomeChart's four
        // counts (In Progress/Won/Hold/Lost) are all zero.
        Livewire::test(ProposalOutcomeChart::class)
            ->assertSee('No data yet');
    }

    public function test_chart_with_data_does_not_show_the_no_data_placeholder(): void
    {
        $user = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
        $lead = Lead::create(['prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id, 'stage' => LeadStage::Validated, 'temperature' => LeadTemperature::Warm, 'notes' => 'meaningful notes here']);
        Proposal::create(['lead_id' => $lead->id, 'prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id, 'stage' => ProposalStage::Sent, 'outcome' => ProposalOutcome::Won, 'value' => 1000, 'notes' => 'x']);

        Livewire::test(ProposalOutcomeChart::class)
            ->assertDontSee('No data yet');
    }

    /**
     * Bug fix: Chart.js's own per-type default aspect ratio differs
     * (doughnut/pie default to a square 1:1, bar/line to a wide 2:1) —
     * with no explicit height, this made "Leads by Stage" (bar) render at
     * a different natural height than "Leads by Temperature"/"Proposal
     * Outcomes" (doughnut) in the same dashboard row. Every non-full-width
     * chart card now gets a fixed 15rem canvas height regardless of type;
     * the full-width trend charts (not part of any 3-column row) are left
     * on Chart.js's own natural sizing.
     */
    public function test_row_card_charts_get_a_fixed_height_for_consistent_sizing(): void
    {
        Livewire::test(LeadsByStageChart::class)->assertSeeHtml('height: 15rem');
        Livewire::test(ProposalOutcomeChart::class)->assertSeeHtml('height: 15rem');
    }

    public function test_full_width_trend_charts_keep_their_natural_height(): void
    {
        Livewire::test(ConversionTrendChart::class)->assertDontSeeHtml('height: 15rem');
        Livewire::test(GrowthTrendChart::class)->assertDontSeeHtml('height: 15rem');
    }

    /**
     * Bug fix: the modal previously used `inset-4`/`md:inset-10` for all
     * four edges, placing its top edge (16px/40px) above the sticky
     * topbar's bottom edge (64px) at every breakpoint — geometrically
     * overlapping it. Live testing across breakpoints/light+dark never
     * actually reproduced the topbar rendering over the modal's heading
     * (z-[61] already wins over the topbar's z-20), but there's no reason
     * for the two to overlap at all — `top-20`/`md:top-24` keeps the
     * modal's top edge below the topbar's height (h-16) unconditionally,
     * removing the overlap regardless of any stacking-context nuance.
     */
    public function test_the_chart_detail_modal_never_overlaps_the_topbar(): void
    {
        Livewire::test(ChartDetailModal::class)
            ->assertSeeHtml('top-20')
            ->assertSeeHtml('md:top-24')
            ->assertDontSeeHtml('inset-4')
            ->assertDontSeeHtml('md:inset-10');
    }
}
