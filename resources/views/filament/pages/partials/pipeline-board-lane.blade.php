@props(['laneKey', 'lane', 'number', 'isDraggableLane', 'isDropTarget'])

{{-- Pipeline Board visual redesign: one lane is one clean Kanban column —
no nested per-stage boxes any more (see PipelineBoard::getLanes()'s own
docblock). The drop target is the LANE itself, not a specific stage; the
resulting dialog (PipelineBoard::dropFormSchema()/crossDropFormSchema())
resolves the actual destination stage, so this partial only needs to know
which lane a card was dropped into, never which stage box. --}}
<div class="flex w-80 shrink-0 flex-col">
    <div
        data-lane="{{ $laneKey }}"
        x-data="{
            over: false,
            // Pipeline Board V2: 'neutral' when nothing being dragged
            // right now carries server-computed eligibility (every lane
            // other than Calls, today — see PipelineBoard::callLane()'s
            // own docblock) — this lane's drag/drop UX is then byte-for-
            // byte identical to before this pass. 'valid'/'invalid' only
            // apply once a card that DOES carry that data starts dragging.
            get dragState() {
                const store = $store.pipelineBoard;

                if (! store.dragActive || store.dragValidDestinations === null) {
                    return 'neutral';
                }

                return store.dragValidDestinations.includes('{{ $laneKey }}') ? 'valid' : 'invalid';
            },
        }"
        @if ($isDropTarget)
            x-on:dragover.prevent="over = true"
            x-on:dragleave="over = false"
            x-on:drop.prevent="
                over = false;
                let dragged = {};
                try { dragged = JSON.parse($event.dataTransfer.getData('text/plain') || '{}'); } catch (e) {}
                if (! dragged.resource) return;
                if (dragged.resource === '{{ $laneKey }}') {
                    $wire.mountAction('drop', { resource: dragged.resource, id: dragged.id });
                } else if (dragState === 'invalid') {
                    // Locked design: an invalid destination disallows the
                    // transition outright — never even opens the modal.
                    // The server's own crossDropSupported() stays the
                    // authoritative gate (see PipelineBoard::
                    // performCrossDrop()); this is purely the matching UX
                    // guard so the two can never visibly disagree.
                    return;
                } else {
                    $wire.mountAction('crossDrop', { sourceResource: dragged.resource, sourceId: dragged.id, destResource: '{{ $laneKey }}' });
                }
            "
            :class="{
                'ring-2 ring-brand-cyan ring-offset-1 ring-offset-white dark:ring-offset-gray-900': over && dragState !== 'invalid',
                'pipeline-board-lane-nodrop': over && dragState === 'invalid',
                'pipeline-board-lane-highlight': dragState === 'valid' && ! over,
                'pipeline-board-lane-dimmed': dragState === 'invalid' && ! over,
            }"
        @endif
        class="pipeline-board-lane flex flex-1 flex-col rounded-xl border p-2.5 transition"
    >
        <div class="pipeline-board-lane-divider mb-2 flex items-baseline gap-2 border-b pb-2">
            <span class="pipeline-board-card-meta-label font-mono text-[10px]">{{ sprintf('%02d', $number) }}</span>
            <h2 class="pipeline-board-card-title text-[13.5px] font-semibold tracking-tight">
                {{ $lane['label'] }}
            </h2>
            <span class="pipeline-board-badge ms-auto shrink-0 rounded-md px-1.5 py-0.5 font-mono text-[10px] font-medium">
                {{ count($lane['cards']) }}
            </span>
        </div>

        {{-- Grows with the card count up to ~10 visible cards, then
        scrolls internally rather than pushing the page's own height
        around — every card past the cap is still present and reachable,
        just scrolled to. Final visual polish pass: `pipeline-board-scroll-
        hidden` removes the scrollbar's own visual chrome (see theme.css)
        without touching `overflow-y-auto` — wheel/trackpad/touch/keyboard
        scrolling all keep working exactly as before, only the bar itself
        no longer clutters the lane. --}}
        <div
            class="pipeline-board-scroll-hidden flex min-h-[4rem] flex-col gap-1.5 overflow-y-auto pe-1"
            style="max-height: 32rem;"
        >
            @forelse ($lane['cards'] as $card)
                @include('filament.pages.partials.pipeline-board-card', [
                    'laneKey' => $laneKey,
                    'card' => $card,
                    'isDraggableLane' => $isDraggableLane,
                ])
            @empty
                <div class="pipeline-board-empty-state rounded-lg border border-dashed py-4 text-center font-mono text-[10px] tracking-wide">
                    No Opportunities
                </div>
            @endforelse
        </div>
    </div>
</div>
