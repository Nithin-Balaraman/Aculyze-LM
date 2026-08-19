{{--
    Read-only content for the "summary" table action's modal — see
    FollowUpResource::companyFollowUpHistory() for how $entries is built
    (each: ['status' => FollowUpStatus, 'occurred_at' => ?Carbon, 'notes' => ?string]).
--}}
<div class="space-y-3">
    @forelse ($entries as $entry)
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="flex items-center justify-between gap-x-3">
                <x-filament::badge :color="$entry['status']->getColor()">
                    {{ $entry['status']->getLabel() }}
                </x-filament::badge>

                <span class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $entry['occurred_at']?->format('d M Y, h:i A') ?? '—' }}
                </span>
            </div>

            <p class="mt-2 text-sm text-gray-950 dark:text-white">
                {{ $entry['notes'] ?: '—' }}
            </p>
        </div>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">
            No completed or cancelled follow-ups for this company yet.
        </p>
    @endforelse
</div>
