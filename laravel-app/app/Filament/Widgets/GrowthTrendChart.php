<?php

namespace App\Filament\Widgets;

use App\Models\Lead;
use App\Models\Proposal;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Date;

/**
 * "Company growth" on the Main Dashboard is an open business question — see
 * AGENTS.md section 32 (Question 5). Rather than invent a revenue-growth
 * formula, this shows a clearly-labeled Leads/Proposals *activity* trend
 * over the last 12 weeks, which can be swapped for a confirmed KPI later
 * without restructuring the dashboard.
 */
class GrowthTrendChart extends ChartWidget
{
    protected static ?string $heading = 'Lead & Proposal Activity Trend (last 12 weeks)';

    protected static ?string $description = 'Provisional activity trend, not a revenue metric — see AGENTS.md §32 for the open "company growth" business question.';

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $weeks = collect(range(11, 0))->map(fn (int $weeksAgo) => Date::now()->subWeeks($weeksAgo)->startOfWeek());

        $leadCounts = $weeks->map(
            fn ($weekStart) => Lead::query()->whereBetween('created_at', [$weekStart, $weekStart->copy()->endOfWeek()])->count()
        );

        $proposalCounts = $weeks->map(
            fn ($weekStart) => Proposal::query()->whereBetween('created_at', [$weekStart, $weekStart->copy()->endOfWeek()])->count()
        );

        return [
            'datasets' => [
                [
                    'label' => 'Leads Created',
                    'data' => $leadCounts->values(),
                    'borderColor' => '#0f766e',
                    'backgroundColor' => 'rgba(15, 118, 110, 0.1)',
                ],
                [
                    'label' => 'Proposals Created',
                    'data' => $proposalCounts->values(),
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.1)',
                ],
            ],
            'labels' => $weeks->map(fn ($weekStart) => $weekStart->format('d M'))->values(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
