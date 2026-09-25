@php
    $trueEnd = $chain === [] ? null : end($chain);
    $trueEndBlockers = $trueEnd ? $trueEnd['own_blockers'] : [];
@endphp

<div class="space-y-4 text-sm">
    @if ($isClean)
        <p class="text-gray-700 dark:text-gray-300">
            The following {{ count($chain) + 1 }} record(s) will be permanently deleted, deepest first, in a
            single transaction:
        </p>

        <ol class="list-inside list-decimal space-y-1 text-gray-700 dark:text-gray-300">
            @foreach (array_reverse($chain) as $node)
                <li>{{ $node['label'] }} (#{{ $node['record']->getKey() }})</li>
            @endforeach
            <li>This Call ({{ $record->prospect?->company_name }} — {{ $record->outcome->getLabel() }})</li>
        </ol>

        <div class="rounded-lg bg-danger-50 p-3 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">
            This cannot be undone. Confirm every record listed above is genuinely wrong before deleting.
        </div>
    @else
        <p class="text-gray-700 dark:text-gray-300">This chain cannot be fully deleted yet.</p>

        <div>
            <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">Downstream Chain</div>
            <ol class="space-y-2">
                <li class="flex items-center gap-2 text-gray-700 dark:text-gray-300">
                    <span class="fi-badge inline-flex items-center rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 dark:bg-gray-500/20 dark:text-gray-300">Call</span>
                    <span>{{ $record->prospect?->company_name }} — {{ $record->outcome->getLabel() }}</span>
                </li>

                @foreach ($chain as $index => $node)
                    @php
                        $isLast = $index === count($chain) - 1;
                        $nodeBlockers = $node['own_blockers'];
                    @endphp
                    <li class="ms-4 flex items-start gap-2 border-s-2 border-gray-200 ps-4 dark:border-white/10">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="fi-badge inline-flex items-center rounded-md px-2 py-1 text-xs font-medium {{ $isLast ? 'bg-primary-100 text-primary-700 dark:bg-primary-500/20 dark:text-primary-400' : 'bg-gray-100 text-gray-700 dark:bg-gray-500/20 dark:text-gray-300' }}">
                                    {{ $node['label'] }}
                                </span>
                                @if ($isLast)
                                    <span class="text-xs font-medium uppercase tracking-wide text-primary-600 dark:text-primary-400">Final link</span>
                                @endif
                            </div>
                            <div class="mt-1 text-xs {{ $nodeBlockers === [] ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">
                                @if ($nodeBlockers === [])
                                    No dependents of its own.
                                @else
                                    Blocked by: {{ collect($nodeBlockers)->map(fn ($count, $label) => "{$count} {$label}")->implode(', ') }}
                                @endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ol>
        </div>

        <div class="rounded-lg bg-danger-50 p-3 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">
            @if ($chain === [])
                No downstream record was found — it may have already been deleted separately, but the Call
                itself still could not be deleted cleanly. Check its own deletion blockers directly.
            @else
                Blocked at {{ $trueEnd['label'] }}: {{ collect($trueEndBlockers)->map(fn ($count, $label) => "{$count} {$label}")->implode(', ') }}.
                Resolve that history first (it may be permanent — e.g. a Proposal with a commercial Version is
                never deleted) before this Call's full chain can be removed.
            @endif
        </div>
    @endif
</div>
