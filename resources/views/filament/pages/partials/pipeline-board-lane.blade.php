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
        x-data="{ over: false }"
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
                } else {
                    $wire.mountAction('crossDrop', { sourceResource: dragged.resource, sourceId: dragged.id, destResource: '{{ $laneKey }}' });
                }
            "
            :class="over ? 'ring-2 ring-brand-cyan ring-offset-1 ring-offset-white dark:ring-offset-gray-900' : ''"
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
