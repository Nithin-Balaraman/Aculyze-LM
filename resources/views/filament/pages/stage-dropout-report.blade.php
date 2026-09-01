<x-filament-panels::page>
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Where prospects drop out of each pipeline. "Moved Past" counts records currently sitting at a later stage than this one. The chart beside each table plots the exact same numbers.
    </p>
    <p class="text-sm text-gray-500 dark:text-gray-400">
        <strong>Legacy report:</strong> this reflects each record's legacy stage progression, retained for historical/compatibility reporting. It is not the authoritative current-workflow view — see the Main Dashboard's Pipeline Pulse and Leads by Status for live, normalized workflow state.
    </p>

    @foreach ($this->getFunnels() as $pipelineName => $stages)
        <x-filament::section :heading="$pipelineName.' Pipeline'">
            <div class="grid gap-6 lg:grid-cols-2">
                <div class="overflow-x-auto">
                    <table class="fi-ta-table w-full text-start">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                <th class="px-3 py-2 text-start text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Stage</th>
                                <th class="px-3 py-2 text-start text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Currently Here</th>
                                <th class="px-3 py-2 text-start text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Moved Past</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($stages as $stage)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="px-3 py-2 text-sm font-medium text-gray-950 dark:text-white">{{ $stage['label'] }}</td>
                                    <td class="px-3 py-2 font-mono text-sm tabular-nums text-gray-700 dark:text-gray-300">{{ $stage['current'] }}</td>
                                    <td class="px-3 py-2 font-mono text-sm tabular-nums text-gray-500 dark:text-gray-400">{{ $stage['movedPast'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div>
                    @if ($this->hasAnyData($stages))
                        <div
                            x-load
                            x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
                            wire:ignore
                            x-data="chart({
                                        cachedData: @js($this->chartData($stages)),
                                        options: @js(['maintainAspectRatio' => false]),
                                        type: 'bar',
                                    })"
                            style="height: 260px"
                        >
                            <canvas x-ref="canvas"></canvas>
                            <span x-ref="backgroundColorElement" class="text-custom-50 dark:text-custom-400/10" @style([\Filament\Support\get_color_css_variables('primary', shades: [50, 400], alias: 'widgets::chart-widget.background')])></span>
                            <span x-ref="borderColorElement" class="text-custom-500 dark:text-custom-400" @style([\Filament\Support\get_color_css_variables('primary', shades: [400, 500], alias: 'widgets::chart-widget.border')])></span>
                            <span x-ref="gridColorElement" class="text-gray-200 dark:text-gray-800"></span>
                            <span x-ref="textColorElement" class="text-gray-500 dark:text-gray-400"></span>
                        </div>
                    @else
                        <div class="flex h-full min-h-[160px] items-center justify-center rounded-lg border border-dashed border-gray-200 text-sm text-gray-400 dark:border-white/10 dark:text-gray-500">
                            No {{ strtolower($pipelineName) }} data yet.
                        </div>
                    @endif
                </div>
            </div>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
