{{--
    Read-only content for the "cardHistory" action's modal — see
    PipelineBoard::cardHistoryData() for how $fields/$lineage/$url/$editUrl
    are built. There is no audit-log table in this schema (only a single
    stage_changed_at timestamp per record), so this is deliberately scoped
    to that one record's own current detail plus its lineage — not a
    fabricated change-by-change timeline. This is also the primary way a
    plain click on a card reaches this record now (replacing straight
    navigation) — $editUrl is the way back to the real Edit page.
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

    <div class="flex items-center gap-3">
        @if ($url)
            <a href="{{ $url }}" class="text-sm font-medium text-brand-cyan hover:underline">View full record →</a>
        @endif

        @if ($editUrl)
            <a
                href="{{ $editUrl }}"
                class="ms-auto rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-white/15 dark:text-gray-200 dark:hover:bg-white/5"
            >Edit</a>
        @endif
    </div>
</div>
