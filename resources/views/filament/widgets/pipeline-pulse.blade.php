@php
    $lastIndex = count($nodes) - 1;
@endphp

<x-filament-widgets::widget>
    <div
        class="pulse-card relative overflow-hidden rounded-2xl p-6 text-white shadow-sm sm:p-8"
        style="background: linear-gradient(135deg, #0E1131 0%, #16205A 55%, #1B2A63 100%);"
    >
        <div class="pointer-events-none absolute inset-0 opacity-[0.07]" style="background-image: radial-gradient(circle at 1px 1px, white 1px, transparent 0); background-size: 22px 22px;"></div>

        <div class="relative mb-6 flex items-start justify-between gap-4 sm:mb-8">
            <div>
                <h2 class="fi-header-heading text-lg font-semibold tracking-tight text-white sm:text-xl">
                    Pipeline Pulse
                </h2>
                <p class="mt-1 text-sm text-white/60">
                    Live flow from first contact to Won, right now.
                </p>
            </div>
            <x-filament::icon
                icon="heroicon-o-signal"
                class="h-6 w-6 shrink-0 text-brand-cyan/70"
            />
        </div>

        <div class="relative flex flex-col sm:flex-row sm:items-center">
            @foreach ($nodes as $i => $node)
                <div
                    @class([
                        'pulse-node flex flex-row items-center gap-3 py-2 sm:flex-1 sm:flex-col sm:items-center sm:gap-2.5 sm:py-0 sm:text-center',
                        'pulse-node-alert' => $node['alert'],
                    ])
                >
                    <div
                        @class([
                            'pulse-node-icon flex h-11 w-11 shrink-0 items-center justify-center rounded-full ring-1 sm:h-12 sm:w-12',
                            'bg-brand-coral/20 ring-brand-coral/60' => $node['alert'],
                            'bg-white/10 ring-white/15' => ! $node['alert'],
                        ])
                    >
                        <x-filament::icon
                            :icon="$node['icon']"
                            @class([
                                'h-5 w-5 sm:h-6 sm:w-6',
                                'text-brand-coral' => $node['alert'],
                                'text-white/80' => ! $node['alert'],
                            ])
                        />
                    </div>

                    <div class="min-w-0">
                        <div
                            @class([
                                'font-mono text-xl font-semibold tabular-nums sm:text-2xl',
                                'text-brand-coral' => $node['alert'],
                                'text-white' => ! $node['alert'],
                            ])
                        >
                            {{ number_format($node['count']) }}
                        </div>
                        <div class="whitespace-nowrap text-[9px] font-normal uppercase tracking-wide text-white/40">
                            {{ $node['tag'] }}
                        </div>
                        <div class="whitespace-nowrap text-[11px] font-bold uppercase tracking-wide text-white/75 sm:text-xs">
                            {{ $node['label'] }}
                        </div>
                    </div>
                </div>

                @if ($i < $lastIndex)
                    @php $connector = $connectors[$i]; @endphp

                    {{-- Mobile: short vertical connector between stacked nodes --}}
                    <div class="flex justify-start py-1 pl-5 sm:hidden">
                        <div
                            @class([
                                'pulse-flow-line-v rounded-full',
                                'pulse-flow-alert' => $connector['alert'],
                            ])
                            style="width: {{ $connector['thickness'] }}px; height: 20px;"
                        ></div>
                    </div>

                    {{-- Desktop: horizontal connector spanning the gap between nodes --}}
                    <div class="hidden shrink-0 items-center px-1 sm:flex sm:w-10">
                        <div
                            @class([
                                'pulse-flow-line-h w-full rounded-full',
                                'pulse-flow-alert' => $connector['alert'],
                            ])
                            style="height: {{ $connector['thickness'] }}px;"
                        ></div>
                    </div>
                @endif
            @endforeach
        </div>
    </div>
</x-filament-widgets::widget>
