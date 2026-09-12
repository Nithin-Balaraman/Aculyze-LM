@props(['laneKey', 'lane', 'number', 'isDraggableLane', 'isDropTarget'])

{{-- Pipeline Board visual redesign: one lane is one clean Kanban column —
no nested per-stage boxes any more (see PipelineBoard::getLanes()'s own
docblock). The drop target is the LANE itself, not a specific stage; the
resulting dialog (PipelineBoard::dropFormSchema()/crossDropFormSchema())
resolves the actual destination stage, so this partial only needs to know
which lane a card was dropped into, never which stage box. --}}
<div class="flex w-72 shrink-0 flex-col">
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
        class="flex flex-1 flex-col rounded-xl border border-gray-200 bg-gray-50 p-2.5 transition dark:border-white/10 dark:bg-white/[0.04]"
    >
        <div class="mb-2 flex items-baseline gap-2 border-b border-gray-200 pb-2 dark:border-white/10">
            <span class="font-mono text-[10px] text-gray-400 dark:text-white/30">{{ sprintf('%02d', $number) }}</span>
            <h2 class="text-[13.5px] font-semibold tracking-tight text-gray-900 dark:text-gray-50">
                {{ $lane['label'] }}
            </h2>
            <span class="ms-auto shrink-0 rounded-md bg-gray-100 px-1.5 py-0.5 font-mono text-[10px] font-medium text-gray-500 dark:bg-white/[0.06] dark:text-gray-400">
                {{ count($lane['cards']) }}
            </span>
        </div>

        {{-- Grows with the card count up to ~10 visible cards, then
        scrolls internally rather than pushing the page's own height
        around — every card past the cap is still present and reachable,
        just scrolled to. --}}
        <div
            class="flex min-h-[4rem] flex-col gap-1.5 overflow-y-auto pe-1 [scrollbar-width:thin] [&::-webkit-scrollbar]:w-1.5 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-gray-300 [&::-webkit-scrollbar-track]:bg-transparent dark:[&::-webkit-scrollbar-thumb]:bg-white/15"
            style="max-height: 32rem;"
        >
            @forelse ($lane['cards'] as $card)
                @include('filament.pages.partials.pipeline-board-card', [
                    'laneKey' => $laneKey,
                    'card' => $card,
                    'isDraggableLane' => $isDraggableLane,
                ])
            @empty
                <div class="rounded-lg border border-dashed border-gray-200 py-4 text-center font-mono text-[10px] tracking-wide text-gray-300 dark:border-white/10 dark:text-white/20">
                    No Opportunities
                </div>
            @endforelse
        </div>
    </div>
</div>
