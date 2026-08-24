<?php

namespace App\Filament\Widgets;

use App\Enums\LeadTemperature;
use App\Filament\Widgets\Concerns\HasClickableChartDetail;
use App\Models\Lead;
use Filament\Widgets\ChartWidget;

class LeadsByTemperatureChart extends ChartWidget
{
    use HasClickableChartDetail;

    protected static string $view = 'filament.widgets.clickable-chart-widget';

    protected static ?string $heading = 'Leads by Temperature';

    protected function getChartKey(): string
    {
        return 'leads-by-temperature';
    }

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

    /**
     * See ProposalOutcomeChart::getOptions() for why this is needed —
     * the same spurious-axis bug affects every doughnut chart in this
     * app, confirmed live on this one too.
     */
    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['display' => false],
                'y' => ['display' => false],
            ],
        ];
    }
}
