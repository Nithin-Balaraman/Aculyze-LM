<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            {{ $this->getHeading() }}
        </x-slot>

        @php
            $tiles = $this->getTiles();
            $callsTile = array_shift($tiles);
            $followUpsTile = $this->getFollowUpsTile();
        @endphp

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
            {{-- Calls -> Follow-Ups -> Appointments -> Leads -> Proposals, matching the process flow. --}}
            @include('filament.widgets.kpi-tile', ['tile' => $callsTile])

            <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
                <div class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <x-filament::icon :icon="$followUpsTile['icon']" class="h-3.5 w-3.5 shrink-0" />
                    {{ $followUpsTile['label'] }}
                </div>

                <div class="mt-1 grid grid-cols-2 gap-2">
                    <div class="min-w-0">
                        <div class="text-xs text-gray-500 dark:text-gray-400">Pending</div>
                        <div class="font-mono text-3xl font-semibold tabular-nums leading-none text-gray-950 dark:text-white">
                            {{ $followUpsTile['pending'] }}
                        </div>
                    </div>
                    <div class="min-w-0">
                        <div class="text-xs text-gray-500 dark:text-gray-400">Completed</div>
                        <div class="font-mono text-3xl font-semibold tabular-nums leading-none text-gray-950 dark:text-white">
                            {{ $followUpsTile['completed'] }}
                        </div>
                    </div>
                </div>

                {{-- No trend line for two separate sub-counts — this spacer
                     just keeps the card's height in line with every other
                     tile's sparkline area. --}}
                <div class="mt-2 text-xs text-gray-400 dark:text-gray-500" style="height: 32px"></div>
            </div>

            @foreach ($tiles as $tile)
                @include('filament.widgets.kpi-tile', ['tile' => $tile])
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
