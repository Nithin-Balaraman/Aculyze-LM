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
partial used before.

Card expansion pass: `expanded` is purely local Alpine state, one instance
per card (declared on this very `<a>` via x-data below) — never persisted,
never a Livewire round-trip, and independent across cards, so any number
of cards can be expanded at once with no accordion behavior. The whole
card stays a single `<a>` with its own click-opens-modal / drag-to-move
behavior UNCHANGED (see PipelineBoard::cardHistoryAction() again) — the
new expand toggle and its revealed detail panel both carry
`x-on:click.stop` so nothing inside them ever bubbles up to the anchor's
own click handler or accidentally opens the modal.

Final visual polish pass: the dedicated corner drag-handle icon is gone —
it was only ever a visual affordance (see the removed span's own comment
in the prior revision); the actual drag source has always been this whole
`<a>` element (`draggable="true"` + `dragstart` below), so removing the
icon changes nothing about drag behavior itself. `expanded`'s toggle now
also nudges the page-level `pipelineBoard` Alpine store's `expandedCount`
(registered once in pipeline-board.blade.php) so the board's one
"Collapse all" control knows whether anything is expanded, and this card
listens for that control's `pipeline-board-collapse-all` window event to
close itself if (and only if) it's currently open — still no server
round-trip, still fully local presentation state. --}}
<a
    x-data="{
        expanded: false,
        toggle() {
            this.expanded = ! this.expanded;
            $store.pipelineBoard.expandedCount += this.expanded ? 1 : -1;
        },
        collapse() {
            if (this.expanded) {
                this.expanded = false;
                $store.pipelineBoard.expandedCount--;
            }
        },
    }"
    x-on:pipeline-board-collapse-all.window="collapse()"
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
    {{-- Top row: small company label + avatar. The whole card is the drag
    source (draggable="true" above) — no separate handle needed. --}}
    <div class="flex items-center gap-1.5">
        <span class="pipeline-board-card-avatar flex h-4 w-4 shrink-0 items-center justify-center rounded-full font-mono text-[8px] font-semibold">
            {{ $card['initials'] }}
        </span>
        <span class="pipeline-board-card-meta-text truncate font-mono text-[10px] uppercase tracking-wide">{{ $card['company'] }}</span>
    </div>

    {{-- Primary title — the card's own prominent, readable headline. Wraps
    up to 2 lines (never clipped to one) rather than truncating, so a long
    company name stays fully readable. --}}
    <div class="pipeline-board-card-title line-clamp-2 break-words text-sm font-semibold leading-tight">
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
    per-lane `meta:` closures). Final visual polish pass: neither wraps as
    a single truncated/clipped line any more — a date/time or a longer
    "what's next" string now wraps onto a second line in full rather than
    being cut off mid-value. --}}
    <div class="flex flex-col gap-0.5">
        @if ($card['assignedTo'] ?? null)
            <div class="pipeline-board-card-meta-text flex items-baseline gap-1 font-mono text-[10px]">
                <span class="pipeline-board-card-meta-label shrink-0">Owner:</span>
                <span class="break-words">{{ $card['assignedTo'] }}</span>
            </div>
        @endif
        <div class="flex items-start gap-1.5">
            <div class="pipeline-board-card-meta-text flex min-w-0 flex-1 items-baseline gap-1 font-mono text-[10px]">
                <span class="pipeline-board-card-meta-label shrink-0">Next:</span>
                <span class="break-words">{{ $card['meta'] }}</span>
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

    @if (! empty($card['details']) || ($card['openCompanyUrl'] ?? null))
        {{-- Card expansion pass: a quick-context panel revealed only by the
        dedicated control below — never by clicking the card itself (that
        still opens the existing detail modal unchanged) and never by
        dragging (the whole card, still the only drag source, is
        untouched). `x-on:click.stop` on this whole block means nothing
        inside it — including a plain click on the text rows — ever
        bubbles up to the card's own click handler. --}}
        <div
            x-show="expanded"
            x-cloak
            x-on:click.stop
            class="pipeline-board-expanded-details flex flex-col gap-1 border-t pt-2"
        >
            @foreach ($card['details'] as $detail)
                <div class="pipeline-board-card-meta-text flex items-baseline gap-1 font-mono text-[10px]">
                    <span class="pipeline-board-card-meta-label shrink-0">{{ $detail['label'] }}:</span>
                    <span class="break-words">{{ $detail['value'] }}</span>
                </div>
            @endforeach

            @if ($card['openCompanyUrl'] ?? null)
                <button
                    type="button"
                    x-on:click.stop.prevent="window.location = '{{ $card['openCompanyUrl'] }}'"
                    class="pipeline-board-open-company-link w-fit font-mono text-[10px] font-semibold transition"
                >Open company →</button>
            @endif
        </div>
    @endif

    {{-- Expand/collapse control — a separate small click target from both
    the card body (modal) and dragging the card (move), placed last so it
    always sits at the bottom of the card regardless of how many badge/meta
    rows precede it. Calls the shared toggle() (see x-data above) so the
    board-level "Collapse all" control's enabled/disabled state stays
    accurate. --}}
    <button
        type="button"
        x-on:click.stop.prevent="toggle()"
        :aria-expanded="expanded.toString()"
        :aria-label="expanded ? 'Hide details' : 'Show more details'"
        class="pipeline-board-expand-btn flex w-full items-center justify-center rounded py-0.5 transition"
    >
        <svg x-show="! expanded" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3 w-3">
            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.19l3.71-3.96a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
        </svg>
        <svg x-show="expanded" x-cloak xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3 w-3">
            <path fill-rule="evenodd" d="M14.77 12.79a.75.75 0 01-1.06-.02L10 8.81l-3.71 3.96a.75.75 0 11-1.08-1.04l4.25-4.5a.75.75 0 011.08 0l4.25 4.5a.75.75 0 01-.02 1.06z" clip-rule="evenodd" />
        </svg>
    </button>
</a>
