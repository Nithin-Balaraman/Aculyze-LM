@php
    // Follow-up, Appointment, Lead, and Proposal are all draggable both
    // within their own lane (a stage mutation, Phase 3) and across into one
    // another (creates a linked record in the target lane and, per the
    // class docblock, also resolves the dragged card forward unless it's
    // already terminal). Call (Phase 4) can be dragged OUT — cross-lane
    // only, into whichever of those four its own outcome didn't already
    // auto-route to — but never accepts a drop itself: a Call Record is
    // only ever created by logging a real call, never by dragging one onto
    // it. Hence two separate lists rather than one.
    $draggableLanes = ['follow_up', 'appointment', 'lead', 'proposal', 'call'];
    $dropTargetLanes = ['follow_up', 'appointment', 'lead', 'proposal'];

    // Purely presentational provenance line under each lane header —
    // mirrors the reference mockup's "from: ..." subtitle. Not derived from
    // $this->getLanes() since it's fixed per lane, not per-record data.
    $laneSubtitles = [
        'call' => 'from: agent logs a call',
        'follow_up' => 'from: Call outcome',
        'appointment' => 'from: Call outcome',
        'lead' => 'from: Call outcome',
        'proposal' => 'from: Lead validated (manual)',
    ];

    // A lane's terminal stages are either one lone box (Lead's Validated —
    // no negative counterpart exists) or a branching pair (Completed/
    // Cancelled, Succeeded/Not Succeeded, Customer Accepted/Rejected).
    // "Negative" is read straight off each stage's own label, the same
    // stable set of words across every lane, rather than a per-lane list.
    $negativeWords = ['not', 'cancel', 'reject', 'lost'];

    // Connector glow (arrows + branch lines) — one shared pair of class
    // strings so every connector uses the exact same brand-cyan glow
    // rather than each spot hand-tuning its own shadow value. drop-shadow
    // (not box-shadow) for the chevrons: they're built from two visible
    // border edges on an otherwise-transparent rotated box, and
    // drop-shadow traces the rendered (border) pixels rather than the
    // full invisible box, so the glow reads as coming from the line
    // itself. The branch lines are real filled bars, so box-shadow (via
    // `shadow-[...]`) works fine for those.
    $glowChevronClasses = 'border-brand-cyan drop-shadow-[0_0_3px_#2DC4ED] dark:drop-shadow-[0_0_5px_#2DC4ED]';
    $glowLineClasses = 'bg-brand-cyan shadow-[0_0_5px_1px_rgba(45,196,237,0.65)] dark:shadow-[0_0_7px_1px_rgba(45,196,237,0.85)]';
@endphp

<x-filament-panels::page>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Every Call, Follow-up, Appointment, Lead, and Proposal you can see, grouped into its real stage. Drag a card within its own lane to move it, or into another lane to create a linked record there.
        </p>

        {{-- Phase 6: filters which cards appear across every lane at once,
        based on each resource's own most meaningful recency date rather
        than a single shared column — see PipelineBoard::periodRange()/
        scopeToPeriod(). A custom Alpine dropdown rather than a native
        <select>: a native select's OPEN options popup is drawn by the OS,
        not the page, so it ignores our dark-mode CSS entirely on some
        platforms even with color-scheme set — this one is fully our own
        markup, so it always matches the board's theme. @entangle(...).live
        keeps it a real two-way binding to the same $period property
        wire:model.live would have used. --}}
        <div
            x-data="{
                open: false,
                value: @entangle('period').live,
                options: [
                    { value: 'all', label: 'All time' },
                    { value: 'today', label: 'Today' },
                    { value: 'week', label: 'This week' },
                    { value: 'month', label: 'This month' },
                    { value: 'quarter', label: 'This quarter' },
                ],
                label() {
                    return this.options.find((option) => option.value === this.value)?.label ?? 'All time';
                },
            }"
            x-on:click.outside="open = false"
            class="relative flex shrink-0 items-center gap-2"
        >
            <label id="pipeline-board-period-label" class="font-mono text-[10px] uppercase tracking-wider text-gray-400 dark:text-white/40">Period</label>
            <button
                type="button"
                aria-haspopup="listbox"
                :aria-expanded="open"
                aria-labelledby="pipeline-board-period-label"
                x-on:click="open = !open"
                class="flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 shadow-sm transition hover:border-gray-400 dark:border-white/10 dark:bg-white/5 dark:text-gray-100 dark:hover:border-white/25"
            >
                <span x-text="label()"></span>
                <span class="text-gray-400 dark:text-white/40">⌄</span>
            </button>

            <div
                x-show="open"
                x-cloak
                x-transition.origin.top.right
                role="listbox"
                class="absolute right-0 top-full z-10 mt-1 w-36 overflow-hidden rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-white/10 dark:bg-gray-800"
            >
                <template x-for="option in options" :key="option.value">
                    <button
                        type="button"
                        role="option"
                        x-on:click="value = option.value; open = false"
                        x-text="option.label"
                        class="block w-full px-3 py-1.5 text-left text-sm transition"
                        :class="value === option.value
                            ? 'font-medium text-brand-cyan'
                            : 'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/10'"
                    ></button>
                </template>
            </div>
        </div>
    </div>

    {{-- data-pipeline-board-scroll: the ONE horizontal scroll container on
    this page (every lane sits inside it) — the auto-scroll script below
    targets this exact node for left/right scrolling while a card is
    dragged near its edge; vertical scrolling targets the page/window
    itself instead, since nothing here scrolls vertically as a unit other
    than the whole document (a stage box's own internal scroll, added
    above, is a small capped area, not the page's primary vertical
    navigation). --}}
    <div data-pipeline-board-scroll class="-mx-4 overflow-x-auto px-4 pb-2 sm:-mx-6 sm:px-6">
        <div class="flex items-start gap-5">
            @foreach ($this->getLanes() as $laneKey => $lane)
                @php
                    $isDraggableLane = in_array($laneKey, $draggableLanes, true);
                    $isDropTarget = in_array($laneKey, $dropTargetLanes, true);

                    // Split into the leading run of non-terminal stages
                    // (rendered in sequence with a connector between each)
                    // and the trailing run of terminal ones (rendered
                    // together — a single box, or side by side with a
                    // BRANCH connector leading into them). Derived purely
                    // from each stage's own `terminal` flag, already present
                    // on every lane's data — no PHP page-class change needed
                    // for this purely visual grouping.
                    $sequentialStages = [];
                    $terminalStages = [];

                    foreach ($lane['stages'] as $stageKey => $stage) {
                        if ($stage['terminal']) {
                            $terminalStages[$stageKey] = $stage;
                        } else {
                            $sequentialStages[$stageKey] = $stage;
                        }
                    }

                    $isNegative = fn (array $stage) => Str::contains(strtolower($stage['label']), $negativeWords);
                @endphp

                <div class="flex w-72 shrink-0 flex-col">
                    {{-- Lane header: deliberately its own block, not styled
                    like a card, so it reads as a section title above the
                    real stage boxes rather than one more box in the stack. --}}
                    <div class="px-0.5 pb-3">
                        <div class="flex items-baseline gap-2">
                            <h2 class="text-[13.5px] font-semibold tracking-tight text-gray-900 dark:text-gray-50">
                                {{ $lane['label'] }}
                            </h2>
                            <span class="rounded-md bg-gray-100 px-1.5 py-0.5 font-mono text-[9px] font-medium uppercase tracking-wider text-gray-500 dark:bg-white/[0.06] dark:text-gray-400">
                                {{ count($lane['stages']) }} {{ Str::plural('stage', count($lane['stages'])) }}
                            </span>
                        </div>
                        @if (isset($laneSubtitles[$laneKey]))
                            <div class="mt-1 font-mono text-[10px] tracking-wide text-gray-400 dark:text-white/30">
                                {{ $laneSubtitles[$laneKey] }}
                            </div>
                        @endif
                    </div>

                    <div class="flex flex-col gap-0">
                        @foreach ($sequentialStages as $stageKey => $stage)
                            @include('filament.pages.partials.pipeline-board-stage-box', [
                                'laneKey' => $laneKey,
                                'stageKey' => $stageKey,
                                'stage' => $stage,
                                'isDraggableLane' => $isDraggableLane,
                                'isDropTarget' => $isDropTarget,
                                'negative' => false,
                            ])

                            @php $isLastSequential = $loop->last; @endphp
                            @if (! $isLastSequential)
                                {{-- Plain chevron: the next box is another sequential (non-terminal) stage. --}}
                                <div class="flex justify-center py-1">
                                    <div @class(['-mt-0.5 h-2 w-2 rotate-45 border-b-[1.5px] border-r-[1.5px]', $glowChevronClasses])></div>
                                </div>
                            @elseif (count($terminalStages) === 1)
                                {{-- Exactly one terminal stage ahead (e.g. Lead's Validated) — same glow, same connector. --}}
                                <div class="flex justify-center py-1">
                                    <div @class(['-mt-0.5 h-2 w-2 rotate-45 border-b-[1.5px] border-r-[1.5px]', $glowChevronClasses])></div>
                                </div>
                            @elseif (count($terminalStages) > 1)
                                {{-- Branching into two terminal outcomes. --}}
                                <div class="relative h-6">
                                    <div @class(['absolute left-1/2 top-0 h-3 w-px -translate-x-1/2', $glowLineClasses])></div>
                                    <div @class(['absolute left-1/4 right-1/4 top-3 h-px', $glowLineClasses])></div>
                                    <div @class(['absolute left-1/4 top-3 h-3.5 w-px', $glowLineClasses])></div>
                                    <div @class(['absolute right-1/4 top-3 h-3.5 w-px', $glowLineClasses])></div>
                                </div>
                            @endif
                        @endforeach

                        @if (count($terminalStages) === 1)
                            @foreach ($terminalStages as $stageKey => $stage)
                                @include('filament.pages.partials.pipeline-board-stage-box', [
                                    'laneKey' => $laneKey,
                                    'stageKey' => $stageKey,
                                    'stage' => $stage,
                                    'isDraggableLane' => $isDraggableLane,
                                    'isDropTarget' => $isDropTarget,
                                    'negative' => $isNegative($stage),
                                ])
                            @endforeach
                        @elseif (count($terminalStages) > 1)
                            {{-- items-start: without it, CSS Grid's default
                            align-items: stretch makes both cells match the
                            row's tallest content — so expanding just ONE
                            terminal box (its own x-show reveals its cards,
                            growing its content height) visually stretched
                            its still-collapsed sibling to the same height
                            too, even though the sibling's own cards stayed
                            hidden. Each box's height is now driven purely by
                            its own content. --}}
                            <div class="grid grid-cols-2 items-start gap-2">
                                @foreach ($terminalStages as $stageKey => $stage)
                                    @include('filament.pages.partials.pipeline-board-stage-box', [
                                        'laneKey' => $laneKey,
                                        'stageKey' => $stageKey,
                                        'stage' => $stage,
                                        'isDraggableLane' => $isDraggableLane,
                                        'isDropTarget' => $isDropTarget,
                                        'negative' => $isNegative($stage),
                                        'compact' => true,
                                    ])
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{--
        Auto-scroll while dragging (Trello/Notion-style edge scrolling).

        HTML5 drag-and-drop does NOT do this for you: browsers fire
        `dragover` with real clientX/clientY the whole time a drag is over
        a valid target, but there is no built-in "scroll the container
        when the pointer nears its edge" behavior for anything other than
        the browser's own outermost document scroll in a couple of
        engines, and that's inconsistent enough not to rely on. Every
        library that has this (Trello, dnd-kit, react-dnd, ...)
        implements it the same way this does: track the pointer position
        from `dragover`, and drive the actual scrolling from a
        requestAnimationFrame loop rather than the dragover events
        themselves — `dragover` firing is throttled and its rate isn't
        consistent across browsers (Firefox in particular fires it far
        less often than Chrome), so scrolling would stutter if it were
        driven directly from the event instead of a steady per-frame loop.

        Two independent targets, matching the board's own layout — there
        is no single "the board" scroll container that covers both axes:
        - Vertical: the page/window itself. Nothing in this layout scrolls
          vertically as a unit other than the whole document (a stage
          box's own internal scroll, added above, is a small ~10-card-cap
          area, not how you'd navigate the length of a lane).
        - Horizontal: the one `[data-pipeline-board-scroll]` wrapper that
          all the lanes sit inside (see its own comment above).

        A plain listener on `document` (not scoped to individual
        drop-target stage boxes) is deliberate: the pointer spends most of
        a real drag over card text, box padding, lane headers, and the
        gaps between lanes — none of which are drop targets — and
        auto-scroll needs to keep working across all of that, not just
        while hovering a valid box. It doesn't call preventDefault()
        itself, so it doesn't change which spots are (or aren't) valid
        drop targets — that's still decided entirely by each stage box's
        own dragover handler.

        Bound once per page load, not re-bound on every Livewire re-render
        (the listeners are on `document`/`window`, which persist across
        this component's own morphs — only the DOM nodes inside it get
        replaced). The `window.__pipelineBoardAutoScrollBound` guard is
        just cheap insurance against ever double-binding if this partial
        somehow gets included/executed more than once.
    --}}
    <script>
        if (! window.__pipelineBoardAutoScrollBound) {
            window.__pipelineBoardAutoScrollBound = true;

            (function () {
                const EDGE_SIZE = 72; // px from the edge where auto-scroll starts
                const MAX_SPEED = 18; // px per animation frame, right at the edge

                let pointer = null;
                let rafId = null;

                // Linear falloff: full speed exactly at the edge, zero at
                // the inner boundary of the EDGE_SIZE zone.
                function speedFor(distanceFromEdge) {
                    const ratio = 1 - (distanceFromEdge / EDGE_SIZE);

                    return Math.max(0, Math.min(1, ratio)) * MAX_SPEED;
                }

                function tick() {
                    if (! pointer) {
                        rafId = null;

                        return;
                    }

                    const viewportHeight = window.innerHeight;

                    if (pointer.y < EDGE_SIZE) {
                        window.scrollBy(0, -speedFor(pointer.y));
                    } else if (pointer.y > viewportHeight - EDGE_SIZE) {
                        window.scrollBy(0, speedFor(viewportHeight - pointer.y));
                    }

                    // Queried fresh every frame rather than cached once:
                    // cheap at 60fps, and safe even if a Livewire render
                    // ever replaced this exact node.
                    const scroller = document.querySelector('[data-pipeline-board-scroll]');

                    if (scroller) {
                        const rect = scroller.getBoundingClientRect();

                        if (pointer.x < rect.left + EDGE_SIZE) {
                            scroller.scrollLeft -= speedFor(pointer.x - rect.left);
                        } else if (pointer.x > rect.right - EDGE_SIZE) {
                            scroller.scrollLeft += speedFor(rect.right - pointer.x);
                        }
                    }

                    rafId = requestAnimationFrame(tick);
                }

                document.addEventListener('dragover', (event) => {
                    pointer = { x: event.clientX, y: event.clientY };

                    if (! rafId) {
                        rafId = requestAnimationFrame(tick);
                    }
                });

                const stop = () => {
                    pointer = null;
                };

                document.addEventListener('drop', stop);
                document.addEventListener('dragend', stop);
            })();
        }
    </script>
</x-filament-panels::page>
