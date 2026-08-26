@php
    // Follow-up, Appointment, Lead, and Proposal are all draggable now
    // (Phase 3) — both within their own lane (a stage mutation) and across
    // into one another (creates a linked record in the target lane and, per
    // the class docblock, also resolves the dragged card forward unless
    // it's already terminal). Call stays out of scope (no stage concept at
    // all) until a later phase.
    $draggableLanes = ['follow_up', 'appointment', 'lead', 'proposal'];
@endphp

<x-filament-panels::page>
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Every Call, Follow-up, Appointment, Lead, and Proposal you can see, grouped into its real stage. Drag a card within its own lane to move it, or into another lane to create a linked record there — Call isn't draggable yet.
    </p>

    <div class="-mx-4 overflow-x-auto px-4 pb-2 sm:-mx-6 sm:px-6">
        <div class="flex items-start gap-4">
            @foreach ($this->getLanes() as $laneKey => $lane)
                @php $isDraggableLane = in_array($laneKey, $draggableLanes, true); @endphp
                <div class="flex w-72 shrink-0 flex-col gap-3">
                    <div class="flex items-center gap-2 px-1">
                        <h2 class="fi-header-heading text-sm font-semibold tracking-tight text-gray-950 dark:text-white">
                            {{ $lane['label'] }}
                        </h2>
                        <span class="rounded-md bg-gray-100 px-1.5 py-0.5 font-mono text-[10px] tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                            {{ count($lane['stages']) }} {{ Str::plural('stage', count($lane['stages'])) }}
                        </span>
                    </div>

                    @foreach ($lane['stages'] as $stageKey => $stage)
                        <div
                            data-lane="{{ $laneKey }}"
                            data-stage="{{ $stageKey }}"
                            @if ($isDraggableLane)
                                x-data="{ over: false }"
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
                                :class="over ? 'ring-2 ring-brand-cyan ring-offset-1 ring-offset-white dark:ring-offset-gray-900' : ''"
                            @endif
                            @class([
                                'rounded-xl border p-2.5 transition',
                                'border-brand-coral/30 bg-brand-coral/5' => $stage['terminal'] && Str::contains(strtolower($stage['label']), ['not', 'cancel', 'reject']),
                                'border-green-500/30 bg-green-500/5 dark:border-green-400/25 dark:bg-green-400/5' => $stage['terminal'] && ! Str::contains(strtolower($stage['label']), ['not', 'cancel', 'reject']),
                                'border-gray-200 bg-white dark:border-white/10 dark:bg-white/[0.03]' => ! $stage['terminal'],
                            ])
                        >
                            <div class="mb-2 flex items-center gap-2">
                                <span
                                    @class([
                                        'h-1.5 w-1.5 shrink-0 rounded-full',
                                        'bg-brand-coral' => $stage['terminal'] && Str::contains(strtolower($stage['label']), ['not', 'cancel', 'reject']),
                                        'bg-green-500' => $stage['terminal'] && ! Str::contains(strtolower($stage['label']), ['not', 'cancel', 'reject']),
                                        'bg-brand-cyan' => ! $stage['terminal'],
                                    ])
                                ></span>
                                <span class="text-xs font-medium text-gray-700 dark:text-gray-200">{{ $stage['label'] }}</span>
                                <span class="ms-auto font-mono text-[10px] text-gray-400 dark:text-gray-500">{{ count($stage['cards']) }}</span>
                            </div>

                            <div class="flex flex-col gap-1.5">
                                @forelse ($stage['cards'] as $card)
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
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
