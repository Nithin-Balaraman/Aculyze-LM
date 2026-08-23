<?php

namespace App\Filament\Widgets;

use App\Enums\LeadStage;
use App\Filament\Widgets\Concerns\HasClickableChartDetail;
use App\Models\Lead;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Section 3 metric: "leads by stage". Reused on both the admin Main
 * Dashboard (employeeId left null -> company-wide) and the per-employee
 * dashboards (employeeId set), same pattern as the other per-employee
 * widgets in this folder.
 */
class LeadsByStageChart extends ChartWidget
{
    use HasClickableChartDetail;

    protected static string $view = 'filament.widgets.clickable-chart-widget';

    protected static ?string $heading = 'Leads by Stage';

    public ?int $employeeId = null;

    protected function getChartKey(): string
    {
        return 'leads-by-stage';
    }

    protected function getChartEmployeeId(): ?int
    {
        return $this->employeeId;
    }

    protected function getData(): array
    {
        $query = Lead::query();

        if ($this->employeeId) {
            $query->where('assigned_to', $this->employeeId);
        }

        $counts = (clone $query)->selectRaw('stage, count(*) as aggregate')->groupBy('stage')->pluck('aggregate', 'stage');

        return [
            'datasets' => [
                [
                    'label' => 'Leads',
                    'data' => collect(LeadStage::cases())->map(fn (LeadStage $stage) => (int) ($counts[$stage->value] ?? 0))->all(),
                    'backgroundColor' => '#4174B9',
                ],
            ],
            'labels' => collect(LeadStage::cases())->map(fn (LeadStage $stage) => $stage->getLabel())->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * Horizontal orientation (`indexAxis: 'y'` — same 'bar' type, just
     * flipped) so the stage-name labels run down the side instead of
     * fighting for horizontal space, which is what was causing Chart.js
     * to auto-rotate them at an angle (confirmed live: reproduced at a
     * ~320px card width, roughly what a 3-column layout gives each
     * card). `stepSize: 1` on the now-horizontal value axis (x, since
     * indexAxis: 'y' swaps which axis holds the counts) keeps ticks at
     * whole numbers regardless of data volume, rather than Chart.js's
     * default "nice number" algorithm producing fractional ticks (0.1,
     * 0.2, ...) for small counts.
     */
    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'scales' => [
                'x' => [
                    'ticks' => ['stepSize' => 1],
                ],
            ],
        ];
    }
}
