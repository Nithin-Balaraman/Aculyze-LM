{{--
    Read-only content for the "cardHistory" action's modal — see
    PipelineBoard::cardHistoryData() for how $fields/$lineage/$resource/$id/
    $canEdit are built. There is no audit-log table in this schema (only a
    single stage_changed_at timestamp per record), so this is deliberately
    scoped to that one record's own current detail plus its lineage — not a
    fabricated change-by-change timeline. This is also the primary way a
    plain click on a card reaches this record now (replacing straight
    navigation). Nothing here ever navigates away from the board (see the
    PipelineBoard class docblock) — "View full record →" and "Edit" both
    swap this modal for another page-level action's
    (viewRecordAction()/editRecordAction()) via $wire.replaceMountedAction(),
    rather than an <a href> to the resource's real page.
    replaceMountedAction(), not mountAction() — two independent page-level
    actions can't simply stack: Filament's getAction() treats a *second*
    mounted action name as a nested modal action declared ON the first
    (see InteractsWithActions::getAction()'s array-name branch), which
    "viewRecord"/"editRecord" aren't, so a plain mountAction() call here
    silently no-ops while "cardHistory" is still open. replaceMountedAction()
    resets the mounted-action state first, so it cleanly closes this modal
    and opens the new one instead — still entirely in-board, just one modal
    at a time rather than stacked.
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
        @if ($resource && $id)
            <button
                type="button"
                x-on:click="$wire.replaceMountedAction('viewRecord', { resource: '{{ $resource }}', id: {{ $id }} })"
                class="text-sm font-medium text-brand-cyan hover:underline"
            >View full record →</button>
        @endif

        @if ($canEdit && $resource && $id)
            <button
                type="button"
                x-on:click="$wire.replaceMountedAction('editRecord', { resource: '{{ $resource }}', id: {{ $id }} })"
                class="ms-auto rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-white/15 dark:text-gray-200 dark:hover:bg-white/5"
            >Edit</button>
        @endif
    </div>
</div>
