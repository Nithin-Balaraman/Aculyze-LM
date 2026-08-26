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
@endphp

<x-filament-panels::page>
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Every Call, Follow-up, Appointment, Lead, and Proposal you can see, grouped into its real stage. Drag a card within its own lane to move it, or into another lane to create a linked record there.
    </p>

    <div class="-mx-4 overflow-x-auto px-4 pb-2 sm:-mx-6 sm:px-6">
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
                                    <div class="-mt-0.5 h-2 w-2 rotate-45 border-b-[1.5px] border-r-[1.5px] border-gray-300 dark:border-white/20"></div>
                                </div>
                            @elseif (count($terminalStages) === 1)
                                {{-- Exactly one terminal stage ahead (e.g. Lead's Validated) — still a
                                plain chevron, just tinted toward the destination's own color. --}}
                                <div class="flex justify-center py-1">
                                    <div class="-mt-0.5 h-2 w-2 rotate-45 border-b-[1.5px] border-r-[1.5px] border-brand-cyan/70"></div>
                                </div>
                            @elseif (count($terminalStages) > 1)
                                {{-- Branching into two terminal outcomes. --}}
                                <div class="relative h-6">
                                    <div class="absolute left-1/2 top-0 h-3 w-px -translate-x-1/2 bg-gray-300 dark:bg-white/15"></div>
                                    <div class="absolute left-1/4 right-1/4 top-3 h-px bg-gray-300 dark:bg-white/15"></div>
                                    <div class="absolute left-1/4 top-3 h-3.5 w-px bg-gray-300 dark:bg-white/15"></div>
                                    <div class="absolute right-1/4 top-3 h-3.5 w-px bg-gray-300 dark:bg-white/15"></div>
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
                            <div class="grid grid-cols-2 gap-2">
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
</x-filament-panels::page>
