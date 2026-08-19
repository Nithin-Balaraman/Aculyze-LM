<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            {{ $this->getHeading() }}
        </x-slot>

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ($this->getTiles() as $tile)
                <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                <x-filament::icon :icon="$tile['icon']" class="h-3.5 w-3.5 shrink-0" />
                                {{ $tile['label'] }}
                            </div>
                            <div class="mt-1 font-mono text-3xl font-semibold tabular-nums leading-none text-gray-950 dark:text-white">
                                {{ $tile['value'] }}
                            </div>
                        </div>

                        @if ($tile['delta'] !== null)
                            <span
                                @class([
                                    'shrink-0 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums',
                                    'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' => $tile['delta'] > 0,
                                    'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400' => $tile['delta'] === 0,
                                    'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-500' => $tile['delta'] < 0,
                                ])
                            >
                                {{ $tile['delta'] > 0 ? '+' : '' }}{{ $tile['delta'] }}%
                            </span>
                        @endif
                    </div>

                    @if ($tile['context'])
                        <div class="mt-1 text-xs text-brand-coral">{{ $tile['context'] }}</div>
                    @endif

                    @if (array_sum($tile['sparkline']) > 0)
                        <div
                            x-load
                            x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
                            wire:ignore
                            x-data="chart({
                                        cachedData: @js([
                                            'labels' => array_fill(0, count($tile['sparkline']), ''),
                                            'datasets' => [[
                                                'data' => $tile['sparkline'],
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
                            style="height: 32px"
                        >
                            <canvas x-ref="canvas"></canvas>
                            <span x-ref="backgroundColorElement" class="text-custom-50 dark:text-custom-400/10" @style([\Filament\Support\get_color_css_variables('primary', shades: [50, 400], alias: 'widgets::chart-widget.background')])></span>
                            <span x-ref="borderColorElement" class="text-custom-500 dark:text-custom-400" @style([\Filament\Support\get_color_css_variables('primary', shades: [400, 500], alias: 'widgets::chart-widget.border')])></span>
                            <span x-ref="gridColorElement" class="text-gray-200 dark:text-gray-800"></span>
                            <span x-ref="textColorElement" class="text-gray-500 dark:text-gray-400"></span>
                        </div>
                    @else
                        <div class="mt-2 text-xs text-gray-400 dark:text-gray-500" style="height: 32px" >No data yet</div>
                    @endif
                </div>
            @endforeach

            @php($followUpTile = $this->getFollowUpsTile())
            <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
                <div class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <x-filament::icon :icon="$followUpTile['icon']" class="h-3.5 w-3.5 shrink-0" />
                    {{ $followUpTile['label'] }}
                </div>

                <div class="mt-1 grid grid-cols-2 gap-4">
                    <div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Pending</div>
                        <div class="font-mono text-3xl font-semibold tabular-nums leading-none text-gray-950 dark:text-white">
                            {{ $followUpTile['pending'] }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Completed</div>
                        <div class="font-mono text-3xl font-semibold tabular-nums leading-none text-gray-950 dark:text-white">
                            {{ $followUpTile['completed'] }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
