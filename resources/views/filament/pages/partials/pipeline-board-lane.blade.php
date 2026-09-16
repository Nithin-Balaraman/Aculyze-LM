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
            // Pipeline Board V2 bugfix: a `get dragState()` accessor
            // defined inside x-data (reading $store.pipelineBoard from its
            // body) does NOT reliably re-trigger this element's :class
            // binding when the store mutates — confirmed empirically
            // (dispatching a real dragstart correctly set
            // $store.pipelineBoard.dragActive/dragValidDestinations, but
            // no lane's class list changed until a native dragover/
            // dragleave on THAT lane separately flipped `over`, which is
            // tracked directly, not via a getter). Alpine's own dependency
            // tracking for a bound directive (:class, x-on, ...) only
            // reliably registers reactive reads that happen literally
            // within that directive's OWN evaluated expression string —
            // not reads buried inside a plain JS getter/method invoked
            // from it. Fixed by reading $store.pipelineBoard directly
            // inside each bound expression below (the same proven-working
            // pattern `over` itself already used), via this one small
            // non-getter helper so the condition isn't duplicated four
            // times — a PLAIN METHOD called as isValidDestination() from
            // within a directive's own expression is invoked synchronously
            // during that directive's evaluation, unlike a getter accessed
            // as a bare property from a DIFFERENT directive's expression.
            isValidDestination() {
                const store = $store.pipelineBoard;

                return store.dragActive && store.dragValidDestinations !== null
                    && store.dragValidDestinations.includes('{{ $laneKey }}');
            },
            isInvalidDestination() {
                const store = $store.pipelineBoard;

                return store.dragActive && store.dragValidDestinations !== null
                    && ! store.dragValidDestinations.includes('{{ $laneKey }}');
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
                } else if (isInvalidDestination()) {
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
                'ring-2 ring-brand-cyan ring-offset-1 ring-offset-white dark:ring-offset-gray-900': over && ! isInvalidDestination(),
                'pipeline-board-lane-nodrop': over && isInvalidDestination(),
                'pipeline-board-lane-highlight': ! over && isValidDestination(),
                'pipeline-board-lane-dimmed': ! over && isInvalidDestination(),
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
