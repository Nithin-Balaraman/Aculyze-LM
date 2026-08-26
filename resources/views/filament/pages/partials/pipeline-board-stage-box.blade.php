@props(['laneKey', 'stageKey', 'stage', 'isDraggableLane', 'isDropTarget', 'negative' => false, 'compact' => false])

@php
    $ringClass = $stage['terminal']
        ? ($negative ? 'ring-brand-coral' : 'ring-green-500')
        : 'ring-brand-cyan';
@endphp

<div
    data-lane="{{ $laneKey }}"
    data-stage="{{ $stageKey }}"
    {{-- Phase 6: terminal-stage boxes (Completed, Cancelled, Succeeded,
    Not Succeeded, Customer Accepted, Customer Rejected, Lead's Lost) start
    collapsed — the count is still visible in the header either way, the
    cards themselves are just hidden until expanded. Non-terminal boxes
    have no such concept and are never collapsible. `over` (drag-hover
    ring) lives in the same x-data regardless, rather than a second one,
    since Alpine doesn't merge multiple x-data objects on one element. --}}
    x-data="{ over: false, collapsed: {{ $stage['terminal'] ? 'true' : 'false' }} }"
    @if ($isDropTarget)
        x-on:dragover.prevent="over = true"
        x-on:dragleave="over = false"
        x-on:drop.prevent="
            over = false;
            let dragged = {};
            try { dragged = JSON.parse($event.dataTransfer.getData('text/plain') || '{}'); } catch (e) {}
            if (! dragged.resource) return;
            if (dragged.resource === '{{ $laneKey }}') {
                if (dragged.fromStage !== '{{ $stageKey }}') {
                    $wire.mountAction('drop', { resource: dragged.resource, id: dragged.id, stage: '{{ $stageKey }}' });
                }
            } else {
                $wire.mountAction('crossDrop', { sourceResource: dragged.resource, sourceId: dragged.id, destResource: '{{ $laneKey }}', destStage: '{{ $stageKey }}' });
            }
        "
        :class="over ? '{{ $ringClass }} ring-2 ring-offset-1 ring-offset-white dark:ring-offset-gray-900' : ''"
    @endif
    @class([
        'rounded-xl border transition',
        $compact ? 'p-2' : 'p-2.5',
        'border-brand-coral/30 bg-brand-coral/10 dark:bg-brand-coral/[0.08]' => $stage['terminal'] && $negative,
        'border-green-500/35 bg-green-500/10 dark:border-green-400/25 dark:bg-green-400/[0.08]' => $stage['terminal'] && ! $negative,
        'border-gray-200 bg-white dark:border-white/10 dark:bg-white/[0.06]' => ! $stage['terminal'],
    ])
>
    <div
        @class(['mb-2 flex gap-2', 'items-start' => $compact, 'items-center' => ! $compact, 'cursor-pointer select-none' => $stage['terminal']])
        @if ($stage['terminal'])
            x-on:click="collapsed = !collapsed"
        @endif
    >
        <span
            @class([
                'mt-1 h-1.5 w-1.5 shrink-0 rounded-full' => $compact,
                'h-1.5 w-1.5 shrink-0 rounded-full' => ! $compact,
                'bg-brand-coral' => $stage['terminal'] && $negative,
                'bg-green-500' => $stage['terminal'] && ! $negative,
                'bg-brand-cyan' => ! $stage['terminal'],
            ])
        ></span>
        <span @class(['text-xs font-medium text-gray-700 dark:text-gray-200', 'leading-tight' => $compact, 'truncate' => ! $compact])>{{ $stage['label'] }}</span>
        @if ($stage['terminal'] && ! $compact)
            <span class="font-mono text-[8px] uppercase tracking-wider text-gray-400 dark:text-white/25">terminal</span>
        @endif
        <span class="ms-auto shrink-0 font-mono text-[10px] text-gray-400 dark:text-gray-500">{{ count($stage['cards']) }}</span>
        @if ($stage['terminal'])
            <span
                class="shrink-0 text-[9px] text-gray-400 transition-transform dark:text-white/30"
                :class="collapsed ? '' : 'rotate-90'"
            >▸</span>
        @endif
    </div>

    <div class="flex flex-col gap-1.5" @if ($stage['terminal']) x-show="! collapsed" x-cloak @endif>
        @forelse ($stage['cards'] as $card)
            {{-- Plain click opens the detail+lineage popup (all resources —
            see PipelineBoard::cardHistoryAction()) instead of navigating
            away. Right-click is reserved for Follow-up's own company-wide
            "Follow-Up History" Summary modal — every other resource has no
            separate right-click behavior, since the click popup already
            covers them. --}}
            <a
                href="{{ $card['url'] }}"
                data-card="{{ $card['resource'] }}-{{ $card['id'] }}"
                @if ($isDraggableLane)
                    draggable="true"
                    x-on:dragstart="$event.dataTransfer.setData('text/plain', JSON.stringify({ resource: '{{ $card['resource'] }}', id: {{ $card['id'] }}, fromStage: '{{ $stageKey }}' })); $el.style.opacity = 0.4"
                    x-on:dragend="$el.style.opacity = 1"
                @else
                    draggable="false"
                @endif
                x-on:click.prevent="$wire.mountAction('cardHistory', { resource: '{{ $card['resource'] }}', id: {{ $card['id'] }} })"
                @if ($card['resource'] === 'follow_up')
                    x-on:contextmenu.prevent="$wire.mountAction('reviewFollowUp', { id: {{ $card['id'] }} })"
                @endif
                class="flex flex-col gap-1 rounded-lg border border-gray-200 bg-white px-2.5 py-2 shadow-sm transition hover:border-gray-300 dark:border-white/10 dark:bg-white/[0.04] dark:hover:border-white/25"
            >
                <div class="flex items-start gap-2">
                    <span class="flex-1 truncate text-xs font-medium text-gray-900 dark:text-gray-100">{{ $card['company'] }}</span>
                    <span class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-gray-100 font-mono text-[8px] font-semibold text-gray-500 dark:bg-white/10 dark:text-gray-300">
                        {{ $card['initials'] }}
                    </span>
                </div>

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

                <div class="flex items-center gap-1.5">
                    <span class="truncate font-mono text-[10px] text-gray-400 dark:text-gray-500">{{ $card['meta'] }}</span>
                    @if ($card['isLost'])
                        <span class="ms-auto shrink-0 rounded bg-brand-coral/15 px-1 font-mono text-[9px] font-semibold text-brand-coral">LOST</span>
                    @elseif ($card['resource'] === 'follow_up')
                        {{-- Reuses the exact "Follow-Up History" summary modal
                        already on the Follow-Ups list page — same company-wide
                        Completed/Cancelled history, same view. Right-click above
                        triggers the same action; this button keeps it discoverable. --}}
                        <button
                            type="button"
                            title="This company's Follow-Up Summary"
                            x-on:click.stop.prevent="$wire.mountAction('reviewFollowUp', { id: {{ $card['id'] }} })"
                            class="ms-auto shrink-0 rounded border border-gray-200 px-1 py-0.5 font-mono text-[9px] font-semibold text-gray-400 transition hover:border-brand-cyan/60 hover:bg-brand-cyan/10 hover:text-brand-cyan dark:border-white/10 dark:text-gray-500"
                        >↻ SUMMARY</button>
                    @endif
                </div>
            </a>
        @empty
            <div class="rounded-lg border border-dashed border-gray-200 py-2 text-center font-mono text-[9px] tracking-wide text-gray-300 dark:border-white/10 dark:text-white/20">
                NONE
            </div>
        @endforelse
    </div>
</div>
