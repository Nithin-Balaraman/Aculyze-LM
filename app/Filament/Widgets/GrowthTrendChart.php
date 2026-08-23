<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\HasClickableChartDetail;
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
    use HasClickableChartDetail;

    protected static string $view = 'filament.widgets.clickable-chart-widget';

    protected static ?string $heading = 'Lead & Proposal Activity Trend (last 12 weeks)';

    protected static ?string $description = 'Provisional activity trend, not a revenue metric — see AGENTS.md §32 for the open "company growth" business question.';

    protected int|string|array $columnSpan = 'full';

    protected function getChartKey(): string
    {
        return 'growth-trend';
    }

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
                    'borderColor' => '#4174B9',
                    'backgroundColor' => 'rgba(65, 116, 185, 0.1)',
                ],
                [
                    'label' => 'Proposals Created',
                    'data' => $proposalCounts->values(),
                    'borderColor' => '#2DC4ED',
                    'backgroundColor' => 'rgba(45, 196, 237, 0.1)',
                ],
            ],
            'labels' => $weeks->map(fn ($weekStart) => $weekStart->format('d M'))->values(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    /** Whole-number ticks regardless of data volume — see LeadsByStageChart::getOptions(). */
    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => [
                    'ticks' => ['stepSize' => 1],
                ],
            ],
        ];
    }
}
