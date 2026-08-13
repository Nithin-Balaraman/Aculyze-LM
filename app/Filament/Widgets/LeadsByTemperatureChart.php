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
                    // Hot uses the brand coral reserved for hot/urgent/
                    // stale states; Warm/Cold stay on the brand blue/cyan
                    // rather than an off-brand red/amber/sky palette.
                    'backgroundColor' => ['#F0653C', '#4174B9', '#2DC4ED'],
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
