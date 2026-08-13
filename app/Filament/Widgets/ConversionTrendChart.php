<?php

namespace App\Filament\Widgets;

use App\Enums\ProposalOutcome;
use App\Models\Proposal;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Date;

/**
 * Section 3 "conversion trend" metric. Per Change Request "Decision 6":
 * conversion = a Proposal reaching the Won outcome. Uses
 * `stage_changed_at` as the "when it was won" timestamp, since that field
 * is updated exactly when `outcome` changes (see Proposal::booted()).
 *
 * The built-in ChartWidget filter dropdown (top-right of the widget) is
 * the "time-period selector" the decision asked for — day/week/month
 * granularity, each over a fixed lookback window.
 */
class ConversionTrendChart extends ChartWidget
{
    protected static ?string $heading = 'Conversion Trend (Proposals Won)';

    protected int|string|array $columnSpan = 'full';

    public ?string $filter = '12w';

    protected function getFilters(): ?array
    {
        return [
            '30d' => 'Last 30 Days (daily)',
            '12w' => 'Last 12 Weeks (weekly)',
            '12m' => 'Last 12 Months (monthly)',
        ];
    }

    protected function getData(): array
    {
        [$buckets, $labelFormat, $unit] = match ($this->filter) {
            '30d' => [30, 'd M', 'day'],
            '12m' => [12, 'M Y', 'month'],
            default => [12, 'd M', 'week'],
        };

        $periods = collect(range($buckets - 1, 0))->map(fn (int $ago) => match ($unit) {
            'day' => Date::now()->subDays($ago)->startOfDay(),
            'month' => Date::now()->subMonths($ago)->startOfMonth(),
            default => Date::now()->subWeeks($ago)->startOfWeek(),
        });

        $counts = $periods->map(function ($start) use ($unit) {
            $end = match ($unit) {
                'day' => $start->copy()->endOfDay(),
                'month' => $start->copy()->endOfMonth(),
                default => $start->copy()->endOfWeek(),
            };

            return Proposal::query()
                ->where('outcome', ProposalOutcome::Won)
                ->whereBetween('stage_changed_at', [$start, $end])
                ->count();
        });

        return [
            'datasets' => [
                [
                    'label' => 'Proposals Won',
                    'data' => $counts->values(),
                    'borderColor' => '#4174B9',
                    'backgroundColor' => 'rgba(65, 116, 185, 0.1)',
                ],
            ],
            'labels' => $periods->map(fn ($start) => $start->format($labelFormat))->values(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
