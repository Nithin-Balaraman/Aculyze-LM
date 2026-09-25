@php
    $flaggedAt = $record->flagged_incorrect_at;
@endphp

<div class="space-y-4 text-sm">
    <dl class="grid grid-cols-2 gap-x-4 gap-y-3">
        <div class="min-w-0">
            <dt class="text-xs font-medium uppercase tracking-wide text-gray-400">Flagged At</dt>
            <dd class="text-gray-950 dark:text-white">{{ $flaggedAt?->format('d M Y, h:i A') ?? '—' }}</dd>
        </div>
        <div class="min-w-0">
            <dt class="text-xs font-medium uppercase tracking-wide text-gray-400">Downstream Record</dt>
            <dd class="text-gray-950 dark:text-white">{{ $downstreamLabel ?? '—' }}</dd>
        </div>
        <div class="col-span-2 min-w-0">
            <dt class="text-xs font-medium uppercase tracking-wide text-gray-400">Reason</dt>
            <dd class="break-words text-gray-950 dark:text-white">{{ $record->flag_reason ?: '—' }}</dd>
        </div>
    </dl>

    <div class="rounded-lg p-3 {{ $downstreamBlockers === [] ? 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' : 'bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400' }}">
        @if (! $downstream)
            <p>No downstream record was found — it may have already been deleted separately.</p>
        @elseif ($downstreamBlockers === [])
            <p>This {{ $downstreamLabel }} has no dependents of its own. A clean two-step delete would work today: delete the {{ $downstreamLabel }} first, then this Call.</p>
        @else
            <p class="font-medium">
                This {{ $downstreamLabel }} still has its own related records and cannot be deleted directly:
            </p>
            <ul class="mt-1 list-inside list-disc">
                @foreach ($downstreamBlockers as $label => $count)
                    <li>{{ $count }} {{ $label }}</li>
                @endforeach
            </ul>
            <p class="mt-1">
                That history must be resolved first (or may be permanent — e.g. a Proposal with a commercial
                Version is never deleted) before this Call can be cleanly removed.
            </p>
        @endif
    </div>
</div>
