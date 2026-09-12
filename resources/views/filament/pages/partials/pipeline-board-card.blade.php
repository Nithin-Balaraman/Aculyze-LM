@props(['laneKey', 'card', 'isDraggableLane'])

{{-- Pipeline Board visual redesign: one reusable card partial for every
lane — plain click opens the detail+lineage popup (all resources — see
PipelineBoard::cardHistoryAction()) instead of navigating away. Right-click
is reserved for Follow-up's own company-wide "Follow-Up History" Summary
modal — every other resource has no separate right-click behavior, since
the click popup already covers them. Dragging is unchanged from before
(the whole card is the drag source, `fromStage` dropped from the payload
since the destination is now resolved inside the drop dialog itself, not
by which stage box the card lands on — see PipelineBoard::
dropCandidateStages()/resolveDestStage()). --}}
<a
    href="{{ $card['url'] }}"
    data-card="{{ $card['resource'] }}-{{ $card['id'] }}"
    @if ($isDraggableLane)
        draggable="true"
        x-on:dragstart="$event.dataTransfer.setData('text/plain', JSON.stringify({ resource: '{{ $card['resource'] }}', id: {{ $card['id'] }} })); $el.style.opacity = 0.4"
        x-on:dragend="$el.style.opacity = 1"
    @else
        draggable="false"
    @endif
    x-on:click.prevent="$wire.mountAction('cardHistory', { resource: '{{ $card['resource'] }}', id: {{ $card['id'] }} })"
    @if ($card['resource'] === 'follow_up')
        x-on:contextmenu.prevent="$wire.mountAction('reviewFollowUp', { id: {{ $card['id'] }} })"
    @endif
    class="group flex flex-col gap-1.5 rounded-lg border border-gray-200 bg-white px-2.5 py-2 shadow-sm transition hover:border-gray-300 hover:shadow dark:border-white/15 dark:bg-white/[0.06] dark:hover:border-white/30"
>
    <div class="flex items-start gap-2">
        <span class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-gray-100 font-mono text-[8px] font-semibold text-gray-500 dark:bg-white/10 dark:text-gray-300">
            {{ $card['initials'] }}
        </span>
        <span class="flex-1 truncate text-xs font-medium text-gray-900 dark:text-gray-100">{{ $card['company'] }}</span>
        @if ($isDraggableLane)
            {{-- Purely visual affordance — the whole card is the actual
            drag source (see draggable="true" above); this just signals
            that it can be picked up. --}}
            <span
                title="Drag to move"
                class="shrink-0 cursor-grab select-none font-mono text-[10px] leading-none text-gray-300 opacity-0 transition group-hover:opacity-100 dark:text-white/25"
            >⠿</span>
        @endif
    </div>

    @if (($card['stageLabel'] ?? null) || $card['outcome'] || ($card['versionStatus'] ?? null))
        <div class="flex flex-wrap items-center gap-1">
            {{-- The record's own internal stage/status — a plain badge now
            that nested per-stage lane containers are gone (see
            PipelineBoard::stageBasedLane()/followUpLane()/demoLane()):
            every lane is one flat card list per main pipeline stage, and
            this is the only place that state is still shown. --}}
            @if ($card['stageLabel'] ?? null)
                <span class="w-fit rounded border border-gray-200 px-1.5 py-0.5 font-mono text-[9px] font-medium tracking-wide text-gray-500 dark:border-white/15 dark:text-gray-400">
                    {{ strtoupper($card['stageLabel']) }}
                </span>
            @endif

            @if ($card['outcome'])
                <span
                    @class([
                        'w-fit rounded border px-1.5 py-0.5 font-mono text-[9px] font-medium tracking-wide',
                        'border-green-500/40 text-green-600 dark:text-green-400' => $card['outcome'] === 'won',
                        'border-brand-gold/50 text-brand-gold' => $card['outcome'] === 'hold',
                        'border-brand-coral/40 text-brand-coral' => $card['outcome'] === 'lost',
                    ])
                >
                    {{ strtoupper($card['outcome']) }}
                </span>
            @endif

            {{-- F6: a read-only Commercial Version Status chip, present
            only on cards that actually have a commercial Version — a
            label, not a lane concept, deliberately styled differently
            from the outcome tag above so the two status systems are never
            mistaken for each other. --}}
            @if ($card['versionStatus'] ?? null)
                <span
                    title="Commercial Version Status"
                    class="w-fit rounded bg-brand-cyan/10 px-1.5 py-0.5 font-mono text-[9px] font-medium tracking-wide text-brand-cyan"
                >CV · {{ strtoupper($card['versionStatus']) }}</span>
            @endif
        </div>
    @endif

    <div class="flex items-center gap-1.5">
        <span class="truncate font-mono text-[10px] text-gray-400 dark:text-gray-500">{{ $card['meta'] }}</span>
        @if ($card['isLost'])
            <span class="ms-auto shrink-0 rounded bg-brand-coral/15 px-1 font-mono text-[9px] font-semibold text-brand-coral">LOST</span>
        @elseif ($card['isOverdue'] ?? false)
            {{-- LOST takes priority over OVERDUE, which takes priority
            over the Follow-Up summary button, when more than one could
            apply to the same card. --}}
            <span class="ms-auto shrink-0 rounded bg-brand-gold/15 px-1 font-mono text-[9px] font-semibold text-brand-gold">OVERDUE</span>
        @elseif ($card['resource'] === 'follow_up')
            {{-- Reuses the exact "Follow-Up History" summary modal already
            on the Follow-Ups list page — same company-wide Completed/
            Cancelled history, same view. Right-click above triggers the
            same action; this button keeps it discoverable. --}}
            <button
                type="button"
                title="This company's Follow-Up Summary"
                x-on:click.stop.prevent="$wire.mountAction('reviewFollowUp', { id: {{ $card['id'] }} })"
                class="ms-auto shrink-0 rounded border border-gray-200 px-1 py-0.5 font-mono text-[9px] font-semibold text-gray-400 transition hover:border-brand-cyan/60 hover:bg-brand-cyan/10 hover:text-brand-cyan dark:border-white/10 dark:text-gray-500"
            >↻ SUMMARY</button>
        @endif
    </div>

    @if ($card['assignedTo'] ?? null)
        <span class="truncate font-mono text-[9px] text-gray-400 dark:text-white/30">
            {{ $card['assignedTo'] }}
        </span>
    @endif
</a>
