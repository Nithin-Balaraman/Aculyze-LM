<x-filament-panels::page>
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Where prospects drop out of each pipeline. "Moved Past" counts records currently sitting at a later stage than this one.
    </p>

    @foreach ($this->getFunnels() as $pipelineName => $stages)
        <x-filament::section :heading="$pipelineName.' Pipeline'">
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
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
