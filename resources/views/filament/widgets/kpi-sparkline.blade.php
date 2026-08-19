{{--
    Included via @include('filament.widgets.kpi-sparkline', ['sparkline' => [...], 'height' => 32])
    from kpi-band.blade.php / kpi-tile.blade.php. $sparkline is a 7-entry
    array of daily counts (see KpiBand::sparklineFor()).
--}}
@php($height = $height ?? 32)

@if (array_sum($sparkline) > 0)
    <div
        x-load
        x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
        wire:ignore
        x-data="chart({
                    cachedData: @js([
                        'labels' => array_fill(0, count($sparkline), ''),
                        'datasets' => [[
                            'data' => $sparkline,
                            'borderColor' => '#4174B9',
                            'backgroundColor' => 'rgba(65, 116, 185, 0.08)',
                            'fill' => true,
                            'tension' => 0.35,
                        ]],
                    ]),
                    options: @js([
                        'maintainAspectRatio' => false,
                        'elements' => ['point' => ['radius' => 0], 'line' => ['borderWidth' => 1.5]],
                        'scales' => ['x' => ['display' => false], 'y' => ['display' => false]],
                        'plugins' => ['legend' => ['display' => false], 'tooltip' => ['enabled' => false]],
                    ]),
                    type: 'line',
                })"
        class="mt-2"
        style="height: {{ $height }}px"
    >
        <canvas x-ref="canvas"></canvas>
        <span x-ref="backgroundColorElement" class="text-custom-50 dark:text-custom-400/10" @style([\Filament\Support\get_color_css_variables('primary', shades: [50, 400], alias: 'widgets::chart-widget.background')])></span>
        <span x-ref="borderColorElement" class="text-custom-500 dark:text-custom-400" @style([\Filament\Support\get_color_css_variables('primary', shades: [400, 500], alias: 'widgets::chart-widget.border')])></span>
        <span x-ref="gridColorElement" class="text-gray-200 dark:text-gray-800"></span>
        <span x-ref="textColorElement" class="text-gray-500 dark:text-gray-400"></span>
    </div>
@else
    <div class="mt-2 text-xs text-gray-400 dark:text-gray-500" style="height: {{ $height }}px">No data yet</div>
@endif
