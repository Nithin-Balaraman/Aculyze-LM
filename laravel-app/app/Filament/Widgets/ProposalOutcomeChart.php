<?php

namespace App\Filament\Widgets;

use App\Enums\ProposalOutcome;
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
    protected static ?string $heading = 'Proposal Outcomes';

    public ?int $employeeId = null;

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
}
