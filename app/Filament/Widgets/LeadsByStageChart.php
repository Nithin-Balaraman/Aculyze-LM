<?php

namespace App\Filament\Widgets;

use App\Enums\LeadStatus;
use App\Filament\Widgets\Concerns\HasClickableChartDetail;
use App\Models\Lead;
use Filament\Widgets\ChartWidget;

/**
 * Section 3 metric: "leads by status". Reused on both the admin Main
 * Dashboard (employeeId left null -> company-wide) and the per-employee
 * dashboards (employeeId set), same pattern as the other per-employee
 * widgets in this folder.
 *
 * Phase 3: migrated from legacy `stage` to normalized `status` — this is a
 * current-facing, live dashboard chart, so it must reflect a Lead's real
 * current workflow state rather than a legacy `stage` that Phase 3's
 * outcome-driven transitions deliberately leave frozen. The class name and
 * `getChartKey()` identifier are kept for compatibility (matching
 * ChartDetailModal's routing); the user-facing heading is what changed.
 */
class LeadsByStageChart extends ChartWidget
{
    use HasClickableChartDetail;

    protected static string $view = 'filament.widgets.clickable-chart-widget';

    protected static ?string $heading = 'Leads by Status';

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

        $counts = (clone $query)->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        return [
            'datasets' => [
                [
                    'label' => 'Leads',
                    'data' => collect(LeadStatus::cases())->map(fn (LeadStatus $status) => (int) ($counts[$status->value] ?? 0))->all(),
                    'backgroundColor' => '#4174B9',
                ],
            ],
            'labels' => collect(LeadStatus::cases())->map(fn (LeadStatus $status) => $status->getLabel())->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * Horizontal orientation (`indexAxis: 'y'` — same 'bar' type, just
     * flipped) so the status-name labels run down the side instead of
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
