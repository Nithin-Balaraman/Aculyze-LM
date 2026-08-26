{{--
    Read-only content for the "cardHistory" action's modal — see
    PipelineBoard::cardHistoryData() for how $fields/$lineage/$url are built.
    There is no audit-log table in this schema (only a single
    stage_changed_at timestamp per record), so this is deliberately scoped
    to that one record's own current detail plus its lineage — not a
    fabricated change-by-change timeline.
--}}
<div class="space-y-4">
    <div class="rounded-lg border border-gray-200 dark:border-white/10">
        @forelse ($fields as $field)
            <div class="flex items-start justify-between gap-x-4 border-b border-gray-100 px-3 py-2 last:border-b-0 dark:border-white/5">
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ $field['label'] }}</span>
                <span class="text-right text-sm text-gray-950 dark:text-white">{{ $field['value'] }}</span>
            </div>
        @empty
            <p class="p-3 text-sm text-gray-500 dark:text-gray-400">No detail available.</p>
        @endforelse
    </div>

    @if (count($lineage) > 0)
        <div>
            <div class="mb-1.5 font-mono text-[10px] uppercase tracking-wider text-gray-400 dark:text-white/30">Lineage</div>
            <ul class="space-y-1">
                @foreach ($lineage as $line)
                    <li class="text-sm text-gray-700 dark:text-gray-300">{{ $line }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($url)
        <a href="{{ $url }}" class="text-sm font-medium text-brand-cyan hover:underline">View full record →</a>
    @endif
</div>
