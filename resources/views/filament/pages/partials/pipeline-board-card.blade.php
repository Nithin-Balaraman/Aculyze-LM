@props(['laneKey', 'card', 'isDraggableLane'])

{{-- Pipeline Board visual redesign (card changeover pass): one reusable
card partial for every lane — plain click opens the detail+lineage popup
(all resources — see PipelineBoard::cardHistoryAction()) instead of
navigating away. Right-click is reserved for Follow-up's own company-wide
"Follow-Up History" Summary modal — every other resource has no separate
right-click behavior, since the click popup already covers them. Dragging
is unchanged from before (the whole card is the drag source, `fromStage`
dropped from the payload since the destination is now resolved inside the
drop dialog itself, not by which stage box the card lands on — see
PipelineBoard::dropCandidateStages()/resolveDestStage()).

Visual hierarchy (this pass, presentation only — every value below already
existed on the card array before this pass; nothing here is new data):
company (small label) + drag handle -> primary title (the company name,
enlarged — this app has one activity per company per lane, not a
separate named "opportunity", so the company name IS the card's title) ->
badge row (stage, outcome, Commercial Version status, Lost/Overdue) ->
Owner/Next meta rows. `.pipeline-board-card` is a hook class carrying the
light/dark surface + rim treatment in theme.css (the same "hook class,
not shared utility class" convention already used for
`.fi-kpi-tile`/`.fi-section-tabs`), so this stays visually consistent with
the rest of the app's cards rather than the flatter ad hoc styling this
partial used before. --}}
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
    class="pipeline-board-card group flex flex-col gap-2 rounded-lg border px-3 py-2.5"
>
    {{-- Top row: small company label (avatar + name) + drag handle. --}}
    <div class="flex items-center gap-1.5">
        <span class="pipeline-board-card-avatar flex h-4 w-4 shrink-0 items-center justify-center rounded-full font-mono text-[8px] font-semibold">
            {{ $card['initials'] }}
        </span>
        <span class="pipeline-board-card-meta-text truncate font-mono text-[10px] uppercase tracking-wide">{{ $card['company'] }}</span>
        @if ($isDraggableLane)
            {{-- Purely visual affordance — the whole card is the actual
            drag source (see draggable="true" above); this just signals
            that it can be picked up. --}}
            <span
                title="Drag to move"
                class="pipeline-board-card-drag-handle ms-auto shrink-0 cursor-grab select-none font-mono text-xs leading-none opacity-0 transition group-hover:opacity-100"
            >⠿⠿</span>
        @endif
    </div>

    {{-- Primary title — the card's own prominent, readable headline. --}}
    <div class="pipeline-board-card-title truncate text-sm font-semibold leading-tight">
        {{ $card['company'] }}
    </div>

    @if (($card['stageLabel'] ?? null) || $card['outcome'] || ($card['versionStatus'] ?? null) || $card['isLost'] || ($card['isOverdue'] ?? false))
        <div class="flex flex-wrap items-center gap-1">
            {{-- The record's own internal stage/status — a plain badge now
            that nested per-stage lane containers are gone (see
            PipelineBoard::stageBasedLane()/followUpLane()/demoLane()):
            every lane is one flat card list per main pipeline stage, and
            this is the only place that state is still shown. --}}
            @if ($card['stageLabel'] ?? null)
                <span class="pipeline-board-badge w-fit rounded px-1.5 py-0.5 font-mono text-[9px] font-medium tracking-wide">
                    {{ strtoupper($card['stageLabel']) }}
                </span>
            @endif

            @if ($card['outcome'])
                <span
                    @class([
                        'pipeline-board-badge w-fit rounded px-1.5 py-0.5 font-mono text-[9px] font-semibold tracking-wide',
                        'pipeline-board-badge-success' => $card['outcome'] === 'won',
                        'pipeline-board-badge-gold' => $card['outcome'] === 'hold',
                        'pipeline-board-badge-coral' => $card['outcome'] === 'lost',
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
                    class="pipeline-board-badge pipeline-board-badge-cyan w-fit rounded px-1.5 py-0.5 font-mono text-[9px] font-semibold tracking-wide"
                >CV · {{ strtoupper($card['versionStatus']) }}</span>
            @endif

            @if ($card['isLost'])
                <span class="pipeline-board-badge pipeline-board-badge-coral ms-auto w-fit shrink-0 rounded px-1.5 py-0.5 font-mono text-[9px] font-semibold tracking-wide">LOST</span>
            @elseif ($card['isOverdue'] ?? false)
                {{-- LOST takes priority over OVERDUE when both could apply
                to the same card. --}}
                <span class="pipeline-board-badge pipeline-board-badge-gold ms-auto w-fit shrink-0 rounded px-1.5 py-0.5 font-mono text-[9px] font-semibold tracking-wide">OVERDUE</span>
            @endif
        </div>
    @endif

    {{-- Meta rows: Owner (assigned employee) / Next (the lane's own
    free-text "what's next" summary — call outcome, appointment time,
    lead temperature, demo mode, proposal value; see PipelineBoard's own
    per-lane `meta:` closures). --}}
    <div class="flex flex-col gap-0.5">
        @if ($card['assignedTo'] ?? null)
            <div class="pipeline-board-card-meta-text flex items-baseline gap-1 truncate font-mono text-[10px]">
                <span class="pipeline-board-card-meta-label shrink-0">Owner:</span>
                <span class="truncate">{{ $card['assignedTo'] }}</span>
            </div>
        @endif
        <div class="flex items-center gap-1.5">
            <div class="pipeline-board-card-meta-text flex min-w-0 flex-1 items-baseline gap-1 truncate font-mono text-[10px]">
                <span class="pipeline-board-card-meta-label shrink-0">Next:</span>
                <span class="truncate">{{ $card['meta'] }}</span>
            </div>
            @if ($card['resource'] === 'follow_up')
                {{-- Reuses the exact "Follow-Up History" summary modal
                already on the Follow-Ups list page — same company-wide
                Completed/Cancelled history, same view. Right-click above
                triggers the same action; this button keeps it
                discoverable. --}}
                <button
                    type="button"
                    title="This company's Follow-Up Summary"
                    x-on:click.stop.prevent="$wire.mountAction('reviewFollowUp', { id: {{ $card['id'] }} })"
                    class="pipeline-board-summary-btn shrink-0 rounded border px-1 py-0.5 font-mono text-[9px] font-semibold transition"
                >↻</button>
            @endif
        </div>
    </div>
</a>
