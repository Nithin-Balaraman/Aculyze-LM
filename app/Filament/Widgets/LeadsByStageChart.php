<?php

namespace App\Filament\Widgets;

use App\Enums\LeadStage;
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
    protected static ?string $heading = 'Leads by Stage';

    public ?int $employeeId = null;

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
}
