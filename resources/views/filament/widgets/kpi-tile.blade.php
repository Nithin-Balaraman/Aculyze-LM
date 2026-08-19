{{-- Included via @include('filament.widgets.kpi-tile', ['tile' => ...]) from kpi-band.blade.php — expects a $tile array shaped like KpiBand::buildTile()'s return value. --}}
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

    @include('filament.widgets.kpi-sparkline', ['sparkline' => $tile['sparkline']])
</div>
