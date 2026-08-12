<?php

namespace App\Filament\Widgets;

use App\Enums\LeadStage;
use App\Models\Lead;
use Filament\Widgets\ChartWidget;

/**
 * Section 3 metric: "leads lost by stage", powered by Lead::lost_at_stage
 * (Change Request "Decision 2") — the stage each Lost Lead was sitting in
 * at the moment it was marked Lost, not its current stage.
 */
class LeadsLostByStageChart extends ChartWidget
{
    protected static ?string $heading = 'Leads Lost by Stage';

    public ?int $employeeId = null;

    protected function getData(): array
    {
        $query = Lead::query()->where('is_lost', true);

        if ($this->employeeId) {
            $query->where('assigned_to', $this->employeeId);
        }

        $counts = (clone $query)->selectRaw('lost_at_stage, count(*) as aggregate')->groupBy('lost_at_stage')->pluck('aggregate', 'lost_at_stage');

        return [
            'datasets' => [
                [
                    'label' => 'Leads Lost',
                    'data' => collect(LeadStage::cases())->map(fn (LeadStage $stage) => (int) ($counts[$stage->value] ?? 0))->all(),
                    'backgroundColor' => '#F0653C',
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
