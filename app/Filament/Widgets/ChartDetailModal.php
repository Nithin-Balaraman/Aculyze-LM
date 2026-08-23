<?php

namespace App\Filament\Widgets;

use App\Enums\LeadStage;
use App\Enums\LeadTemperature;
use App\Enums\ProposalOutcome;
use App\Filament\Resources\LeadResource;
use App\Filament\Resources\ProposalResource;
use App\Models\Lead;
use App\Models\Proposal;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Date;
use Livewire\Attributes\On;

/**
 * The click-to-detail modal for every chart widget using
 * App\Filament\Widgets\Concerns\HasClickableChartDetail. Registered once
 * per dashboard (MainDashboard, MyDashboard) — not per chart — since only
 * one detail view is ever open at a time; which chart's content it shows
 * is driven entirely by `$chartKey`/`$employeeId`, set via the
 * `chart-detail-open` browser event every clickable chart card dispatches
 * (see resources/views/filament/widgets/clickable-chart-widget.blade.php).
 *
 * The actual zoom-from-the-clicked-card animation is handled client-side,
 * in resources/views/filament/widgets/chart-detail-modal.blade.php's own
 * Alpine code, which listens for the same browser event directly (not
 * through Livewire) so the animation starts immediately rather than
 * waiting on this component's round trip.
 *
 * `getDetail()` deliberately does NOT reuse each chart widget's own
 * getData()/getOptions() by instantiating that widget class — those are
 * `protected` methods tied to Livewire's own per-widget mount lifecycle,
 * and reaching across widget instances would be more fragile than just
 * duplicating the (small) query logic here, matching each widget's own
 * chart data shape closely enough that the same Alpine chart() component
 * renders it identically, just larger.
 */
class ChartDetailModal extends Widget
{
    protected static string $view = 'filament.widgets.chart-detail-modal';

    protected int|string|array $columnSpan = 'full';

    /**
     * Every Filament widget defaults to lazy-loaded (Filament\Support\
     * Concerns\CanBeLazy — fetched via a follow-up request once it
     * scrolls into view, not part of the initial page HTML). Confirmed
     * live this was silently breaking the whole feature: the modal's own
     * `<template x-teleport="body">` markup and its
     * #[On('chart-detail-open')] listener never existed in the DOM at
     * all until the widget lazy-loaded — and a hidden modal widget never
     * scrolls into view, so it never did. Must render eagerly.
     */
    protected static bool $isLazy = false;

    public bool $isOpen = false;

    public ?string $chartKey = null;

    public ?int $employeeId = null;

    #[On('chart-detail-open')]
    public function open(string $chartKey, ?int $employeeId = null): void
    {
        $this->chartKey = $chartKey;
        $this->employeeId = $employeeId;
        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->isOpen = false;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetail(): array
    {
        return match ($this->chartKey) {
            'proposal-outcomes' => $this->proposalOutcomesDetail(),
            'leads-by-temperature' => $this->leadsByTemperatureDetail(),
            'leads-by-stage' => $this->leadsByStageDetail(),
            'leads-lost-by-stage' => $this->leadsLostByStageDetail(),
            'conversion-trend' => $this->conversionTrendDetail(),
            'growth-trend' => $this->growthTrendDetail(),
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function proposalOutcomesDetail(): array
    {
        $query = Proposal::query();

        if ($this->employeeId) {
            $query->where('assigned_to', $this->employeeId);
        }

        $outcomes = [
            'In Progress' => null,
            'Won' => ProposalOutcome::Won,
            'Hold' => ProposalOutcome::Hold,
            'Lost' => ProposalOutcome::Lost,
        ];

        $total = (clone $query)->count();
        $breakdown = [];
        $counts = [];

        foreach ($outcomes as $label => $outcome) {
            $scoped = (clone $query)->when(
                $outcome === null,
                fn ($q) => $q->whereNull('outcome'),
                fn ($q) => $q->where('outcome', $outcome),
            );

            $count = (clone $scoped)->count();
            $value = (float) (clone $scoped)->sum('value');
            $counts[] = $count;

            $breakdown[] = [
                'label' => $label,
                'count' => $count,
                'percentage' => $total > 0 ? round($count / $total * 100, 1) : 0.0,
                'meta' => '$'.number_format($value, 2).' total value',
            ];
        }

        $recentQuery = (clone $query)->whereNotNull('outcome')->latest('stage_changed_at')->limit(10)->get();

        return [
            'heading' => 'Proposal Outcomes',
            'type' => 'doughnut',
            'chartData' => [
                'datasets' => [[
                    'data' => $counts,
                    'backgroundColor' => ['#4174B9', '#2DC4ED', '#94a3b8', '#0E1131'],
                ]],
                'labels' => array_keys($outcomes),
            ],
            'chartOptions' => [
                'scales' => [
                    'x' => ['display' => false],
                    'y' => ['display' => false],
                ],
            ],
            'breakdown' => $breakdown,
            'listHeading' => 'Recent Proposals',
            'listItems' => $recentQuery->map(fn (Proposal $proposal) => [
                'label' => $proposal->prospect?->company_name ?? "Proposal #{$proposal->id}",
                'sublabel' => $proposal->outcome?->getLabel() ?? 'In Progress',
                'url' => ProposalResource::getUrl('edit', ['record' => $proposal]),
            ])->all(),
            'listUrl' => ['url' => ProposalResource::getUrl('index'), 'label' => 'View all Proposals'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function leadsByTemperatureDetail(): array
    {
        $counts = Lead::query()
            ->selectRaw('temperature, count(*) as aggregate')
            ->groupBy('temperature')
            ->pluck('aggregate', 'temperature');

        $total = (int) $counts->sum();

        $temperatures = [
            LeadTemperature::Hot->value => 'Hot',
            LeadTemperature::Warm->value => 'Warm',
            LeadTemperature::Cold->value => 'Cold',
        ];

        $breakdown = [];
        $data = [];

        foreach ($temperatures as $value => $label) {
            $count = (int) ($counts[$value] ?? 0);
            $data[] = $count;

            $breakdown[] = [
                'label' => $label,
                'count' => $count,
                'percentage' => $total > 0 ? round($count / $total * 100, 1) : 0.0,
                'meta' => null,
            ];
        }

        $hotLeads = Lead::query()->where('temperature', LeadTemperature::Hot)->latest()->limit(10)->get();

        return [
            'heading' => 'Leads by Temperature',
            'type' => 'doughnut',
            'chartData' => [
                'datasets' => [[
                    'data' => $data,
                    'backgroundColor' => ['#F0653C', '#4174B9', '#2DC4ED'],
                ]],
                'labels' => array_values($temperatures),
            ],
            'chartOptions' => [
                'scales' => [
                    'x' => ['display' => false],
                    'y' => ['display' => false],
                ],
            ],
            'breakdown' => $breakdown,
            'listHeading' => 'Hot Leads',
            'listItems' => $hotLeads->map(fn (Lead $lead) => [
                'label' => $lead->prospect?->company_name ?? "Lead #{$lead->id}",
                'sublabel' => $lead->stage->getLabel(),
                'url' => LeadResource::getUrl('edit', ['record' => $lead]),
            ])->all(),
            'listUrl' => ['url' => LeadResource::getUrl('index'), 'label' => 'View all Leads'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function leadsByStageDetail(): array
    {
        $query = Lead::query();

        if ($this->employeeId) {
            $query->where('assigned_to', $this->employeeId);
        }

        $counts = (clone $query)->selectRaw('stage, count(*) as aggregate')->groupBy('stage')->pluck('aggregate', 'stage');
        $total = (int) $counts->sum();

        $stages = LeadStage::cases();

        $breakdown = collect($stages)->map(function (LeadStage $stage) use ($counts, $total) {
            $count = (int) ($counts[$stage->value] ?? 0);

            return [
                'label' => $stage->getLabel(),
                'count' => $count,
                'percentage' => $total > 0 ? round($count / $total * 100, 1) : 0.0,
                'meta' => null,
            ];
        })->all();

        return [
            'heading' => 'Leads by Stage',
            'type' => 'bar',
            'chartData' => [
                'datasets' => [[
                    'label' => 'Leads',
                    'data' => collect($stages)->map(fn (LeadStage $s) => (int) ($counts[$s->value] ?? 0))->all(),
                    'backgroundColor' => '#4174B9',
                ]],
                'labels' => collect($stages)->map(fn (LeadStage $s) => $s->getLabel())->all(),
            ],
            'chartOptions' => [
                'indexAxis' => 'y',
                'scales' => ['x' => ['ticks' => ['stepSize' => 1]]],
            ],
            'breakdown' => $breakdown,
            'trend' => $this->stageTrend($stages, 'stage', 'stage_changed_at', false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function leadsLostByStageDetail(): array
    {
        $query = Lead::query()->where('is_lost', true);

        if ($this->employeeId) {
            $query->where('assigned_to', $this->employeeId);
        }

        $counts = (clone $query)->selectRaw('lost_at_stage, count(*) as aggregate')->groupBy('lost_at_stage')->pluck('aggregate', 'lost_at_stage');
        $total = (int) $counts->sum();

        $stages = LeadStage::cases();

        $breakdown = collect($stages)->map(function (LeadStage $stage) use ($counts, $total) {
            $count = (int) ($counts[$stage->value] ?? 0);

            return [
                'label' => $stage->getLabel(),
                'count' => $count,
                'percentage' => $total > 0 ? round($count / $total * 100, 1) : 0.0,
                'meta' => null,
            ];
        })->all();

        return [
            'heading' => 'Leads Lost by Stage',
            'type' => 'bar',
            'chartData' => [
                'datasets' => [[
                    'label' => 'Leads Lost',
                    'data' => collect($stages)->map(fn (LeadStage $s) => (int) ($counts[$s->value] ?? 0))->all(),
                    'backgroundColor' => '#F0653C',
                ]],
                'labels' => collect($stages)->map(fn (LeadStage $s) => $s->getLabel())->all(),
            ],
            'chartOptions' => [
                'indexAxis' => 'y',
                'scales' => ['x' => ['ticks' => ['stepSize' => 1]]],
            ],
            'breakdown' => $breakdown,
            'trend' => $this->stageTrend($stages, 'lost_at_stage', 'lost_at', true),
        ];
    }

    /**
     * A per-stage trend over the last 12 weeks — the same weekly-bucket
     * technique ConversionTrendChart already uses, applied per stage
     * instead of a single Won/Lost count. `$dateColumn` is the timestamp
     * that actually reflects "when this lead reached/was marked at this
     * stage" (`stage_changed_at` for the current-stage distribution,
     * `lost_at` for when it was lost at that stage) — there's no
     * historical stage-snapshot table, so this reads as "leads whose
     * stage-relevant date falls in this week," not a full audit trail.
     *
     * @param  array<LeadStage>  $stages
     * @return array<string, mixed>
     */
    private function stageTrend(array $stages, string $stageColumn, string $dateColumn, bool $lostOnly): array
    {
        $weeks = collect(range(11, 0))->map(fn (int $ago) => Date::now()->subWeeks($ago)->startOfWeek());

        $colors = ['#4174B9', '#2DC4ED', '#F0653C', '#94a3b8', '#0E1131'];

        $datasets = collect($stages)->values()->map(function (LeadStage $stage, int $index) use ($weeks, $stageColumn, $dateColumn, $lostOnly, $colors) {
            $data = $weeks->map(function ($weekStart) use ($stage, $stageColumn, $dateColumn, $lostOnly) {
                $query = Lead::query()->where($stageColumn, $stage->value);

                if ($lostOnly) {
                    $query->where('is_lost', true);
                }

                if ($this->employeeId) {
                    $query->where('assigned_to', $this->employeeId);
                }

                return $query->whereBetween($dateColumn, [$weekStart, $weekStart->copy()->endOfWeek()])->count();
            });

            return [
                'label' => $stage->getLabel(),
                'data' => $data->values(),
                'borderColor' => $colors[$index % count($colors)],
                'backgroundColor' => 'transparent',
            ];
        });

        return [
            'labels' => $weeks->map(fn ($weekStart) => $weekStart->format('d M'))->values(),
            'datasets' => $datasets->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function conversionTrendDetail(): array
    {
        $weeks = collect(range(11, 0))->map(fn (int $ago) => Date::now()->subWeeks($ago)->startOfWeek());

        $counts = $weeks->map(function ($weekStart) {
            return Proposal::query()
                ->where('outcome', ProposalOutcome::Won)
                ->whereBetween('stage_changed_at', [$weekStart, $weekStart->copy()->endOfWeek()])
                ->count();
        });

        $labels = $weeks->map(fn ($weekStart) => $weekStart->format('d M'))->values();

        return [
            'heading' => 'Conversion Trend (Proposals Won)',
            'type' => 'line',
            'chartData' => [
                'datasets' => [[
                    'label' => 'Proposals Won',
                    'data' => $counts->values(),
                    'borderColor' => '#4174B9',
                    'backgroundColor' => 'rgba(65, 116, 185, 0.1)',
                ]],
                'labels' => $labels,
            ],
            'chartOptions' => [
                'scales' => ['y' => ['ticks' => ['stepSize' => 1]]],
            ],
            'table' => $labels->map(fn ($label, $i) => [
                'period' => $label,
                'value' => $counts->values()[$i],
            ])->all(),
            'tableValueLabel' => 'Proposals Won',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function growthTrendDetail(): array
    {
        $weeks = collect(range(11, 0))->map(fn (int $ago) => Date::now()->subWeeks($ago)->startOfWeek());

        $leadCounts = $weeks->map(
            fn ($weekStart) => Lead::query()->whereBetween('created_at', [$weekStart, $weekStart->copy()->endOfWeek()])->count()
        );

        $proposalCounts = $weeks->map(
            fn ($weekStart) => Proposal::query()->whereBetween('created_at', [$weekStart, $weekStart->copy()->endOfWeek()])->count()
        );

        $labels = $weeks->map(fn ($weekStart) => $weekStart->format('d M'))->values();

        return [
            'heading' => 'Lead & Proposal Activity Trend (last 12 weeks)',
            'type' => 'line',
            'chartData' => [
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
                'labels' => $labels,
            ],
            'chartOptions' => [
                'scales' => ['y' => ['ticks' => ['stepSize' => 1]]],
            ],
            'table' => $labels->map(fn ($label, $i) => [
                'period' => $label,
                'leadsCreated' => $leadCounts->values()[$i],
                'proposalsCreated' => $proposalCounts->values()[$i],
            ])->all(),
        ];
    }
}
