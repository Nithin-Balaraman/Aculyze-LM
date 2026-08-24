<?php

namespace App\Filament\Widgets;

use App\Enums\ProposalOutcome;
use App\Filament\Widgets\Concerns\HasClickableChartDetail;
use App\Models\Proposal;
use Filament\Widgets\ChartWidget;

/**
 * UX Fixes Batch Issue 6 (chart variety): a donut for the Proposal outcome
 * distribution (Won/Hold/Lost/In Progress), reusing the employeeId-optional
 * pattern from LeadsByStageChart. Hold uses a neutral gray rather than
 * coral — coral stays reserved for hot/urgent/stale, not a generic
 * "pending" state.
 */
class ProposalOutcomeChart extends ChartWidget
{
    use HasClickableChartDetail;

    protected static string $view = 'filament.widgets.clickable-chart-widget';

    protected static ?string $heading = 'Proposal Outcomes';

    public ?int $employeeId = null;

    protected function getChartKey(): string
    {
        return 'proposal-outcomes';
    }

    protected function getChartEmployeeId(): ?int
    {
        return $this->employeeId;
    }

    protected function getData(): array
    {
        $query = Proposal::query();

        if ($this->employeeId) {
            $query->where('assigned_to', $this->employeeId);
        }

        $counts = (clone $query)->selectRaw('outcome, count(*) as aggregate')->groupBy('outcome')->pluck('aggregate', 'outcome');
        $inProgress = (int) (clone $query)->whereNull('outcome')->count();

        return [
            'datasets' => [
                [
                    'data' => [
                        $inProgress,
                        (int) ($counts[ProposalOutcome::Won->value] ?? 0),
                        (int) ($counts[ProposalOutcome::Hold->value] ?? 0),
                        (int) ($counts[ProposalOutcome::Lost->value] ?? 0),
                    ],
                    'backgroundColor' => ['#4174B9', '#2DC4ED', '#94a3b8', '#0E1131'],
                ],
            ],
            'labels' => ['In Progress', 'Won', 'Hold', 'Lost'],
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    /**
     * Filament's own chart Alpine component (vendor/filament/widgets/
     * resources/js/components/chart.js) unconditionally merges in an
     * x/y scales config for every chart type — it only ever hides the
     * grid *lines* (`grid.display ??= false`), never the axis itself, so
     * a doughnut (which normally has no axes at all) was rendering a
     * spurious numeric 0/1/2/3 axis with meaningless auto-generated
     * ticks. Explicitly disabling both axes here is the fix; confirmed
     * live that this also affects every other doughnut chart in this app
     * (see LeadsByTemperatureChart), not just this one.
     */
    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['display' => false],
                'y' => ['display' => false],
            ],
        ];
    }
}
