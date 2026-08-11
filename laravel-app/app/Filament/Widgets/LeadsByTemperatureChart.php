<?php

namespace App\Filament\Widgets;

use App\Enums\LeadTemperature;
use App\Models\Lead;
use Filament\Widgets\ChartWidget;

class LeadsByTemperatureChart extends ChartWidget
{
    protected static ?string $heading = 'Leads by Temperature';

    protected function getData(): array
    {
        $counts = Lead::query()
            ->selectRaw('temperature, count(*) as aggregate')
            ->groupBy('temperature')
            ->pluck('aggregate', 'temperature');

        return [
            'datasets' => [
                [
                    'data' => [
                        $counts->get(LeadTemperature::Hot->value, 0),
                        $counts->get(LeadTemperature::Warm->value, 0),
                        $counts->get(LeadTemperature::Cold->value, 0),
                    ],
                    'backgroundColor' => ['#ef4444', '#f59e0b', '#38bdf8'],
                ],
            ],
            'labels' => ['Hot', 'Warm', 'Cold'],
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
