{{--
    The click-to-detail modal for every "clickable" chart widget (see
    App\Filament\Widgets\Concerns\HasClickableChartDetail and
    clickable-chart-widget.blade.php). Registered once per dashboard by
    App\Filament\Widgets\ChartDetailModal — this file is its entire view.

    Two things drive this, deliberately kept independent so the animation
    never waits on the server:
    1. `x-data` below listens directly for the `chart-detail-open` browser
       event (dispatched by whichever chart card was clicked) to run the
       "grow from the clicked card's on-screen rect to fill the viewport"
       CSS transform animation, immediately on click.
    2. The SAME event, being a plain browser CustomEvent, is separately
       picked up by Livewire's own global-event-to-PHP-listener bridge,
       calling ChartDetailModal::open() — which sets $isOpen/$chartKey and
       triggers a normal Livewire re-render carrying the real chart/
       breakdown content from getDetail(). Until that lands, the modal
       shows a brief loading placeholder (see `wire:loading` below).

    Respects prefers-reduced-motion: skips the scale-from-rect transform
    entirely and just shows the modal at full size immediately (no
    transition of any kind), rather than substituting a different
    animation — same spirit as the reduced-motion guards already used for
    the Pipeline Pulse animation and card hover-lift (see resources/css/
    filament/admin/theme.css).
--}}
{{--
    x-teleport must be on a <template> per Alpine's own documented
    pattern (it clones the template's content into the target location);
    the x-data scope lives on this outer, non-teleported div, and stays
    reachable from the teleported content via Alpine's normal closure
    scoping — this outer div itself renders nothing visible.
--}}
<div
    x-data="{
        show: false,
        style: '',
        transitionCss: 'transition: transform 300ms cubic-bezier(0.16, 1, 0.3, 1), opacity 250ms ease-out;',
        close() {
            this.show = false;
            $wire.close();
        },
        init() {
            window.addEventListener('chart-detail-open', (e) => {
                const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                this.show = true;

                if (prefersReducedMotion) {
                    this.style = '';
                    return;
                }

                const r = e.detail.rect;
                const scaleX = Math.max(r.width / window.innerWidth, 0.05);
                const scaleY = Math.max(r.height / window.innerHeight, 0.05);
                const originX = r.left + (r.width / 2);
                const originY = r.top + (r.height / 2);

                // The transition property has to live inside this SAME
                // string, not a separate static `style=` attribute on the
                // element — Alpine's x-bind:style, given a plain string,
                // replaces the whole inline style on every update (found
                // live: a separate static `style` attribute holding just
                // `transition: ...` was being silently wiped out on the
                // very first x-bind:style write, so the transform jumped
                // instantly with no easing at all — 'technically correct'
                // start/end state, but not the smooth zoom this was for).
                this.style = `${this.transitionCss} transform-origin: ${originX}px ${originY}px; transform: scale(${scaleX}, ${scaleY}); opacity: 0;`;

                requestAnimationFrame(() => requestAnimationFrame(() => {
                    this.style = `${this.transitionCss} transform: scale(1, 1); opacity: 1;`;
                }));
            });

            window.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && this.show) this.close();
            });
        },
    }"
>
<template x-teleport="body">
    <div x-show="show" x-cloak>
    <div class="fi-chart-detail-backdrop fixed inset-0 z-[60] bg-gray-950/60" x-on:click="close()"></div>

    {{--
        top-20/md:top-24 (5rem/6rem) instead of the plain inset-4/md:inset-10
        top used for the other 3 edges — keeps the modal's top edge below
        the sticky topbar's own height (h-16 = 4rem) at every breakpoint,
        so the modal never geometrically overlaps it at all. Live testing
        (desktop, mobile-width, light/dark) never reproduced the topbar
        actually rendering over the modal's heading — z-[61] vs. the
        topbar's z-20 already wins — but there's no reason for the two to
        overlap in the first place, and removing the overlap outright is
        more robust than relying on stacking-context correctness alone.
    --}}
    <div
        class="fi-chart-detail-modal fixed inset-x-4 bottom-4 top-20 z-[61] overflow-y-auto rounded-2xl bg-white shadow-2xl dark:bg-gray-900 md:inset-x-10 md:bottom-10 md:top-24"
        x-bind:style="style"
        x-on:click.outside="close()"
    >
        <div class="fi-chart-detail-modal-header sticky top-0 z-10 flex items-center justify-between border-b border-gray-200 bg-white px-6 py-4 dark:border-white/10 dark:bg-gray-900">
            <h2 class="text-xl font-bold text-gray-950 dark:text-white">
                {{ $this->isOpen ? ($this->getDetail()['heading'] ?? '') : '' }}
            </h2>

            <x-filament::icon-button
                icon="heroicon-o-x-mark"
                icon-alias="chart-detail-modal.close-button"
                color="gray"
                label="Close"
                x-on:click="close()"
            />
        </div>

        <div class="px-6 py-6" wire:loading.remove wire:target="open">
            @if ($this->isOpen)
                @php($detail = $this->getDetail())

                <div
                    @if (\Filament\Support\Facades\FilamentView::hasSpaMode())
                        x-load="visible"
                    @else
                        x-load
                    @endif
                    x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
                    wire:ignore
                    x-data="chart({
                        cachedData: @js($detail['chartData'] ?? []),
                        options: @js($detail['chartOptions'] ?? null),
                        type: @js($detail['type'] ?? 'bar'),
                    })"
                    class="fi-color-custom"
                    @style([
                        \Filament\Support\get_color_css_variables('primary', shades: [50, 400], alias: 'widgets::chart-widget.background'),
                    ])
                >
                    <canvas x-ref="canvas" style="max-height: 22rem"></canvas>
                    <span x-ref="backgroundColorElement" class="text-custom-50 dark:text-custom-400/10" @style([\Filament\Support\get_color_css_variables('primary', shades: [50, 400], alias: 'widgets::chart-widget.background')])></span>
                    <span x-ref="borderColorElement" class="text-custom-500 dark:text-custom-400" @style([\Filament\Support\get_color_css_variables('primary', shades: [400, 500], alias: 'widgets::chart-widget.border')])></span>
                    <span x-ref="gridColorElement" class="text-gray-200 dark:text-gray-800"></span>
                    <span x-ref="textColorElement" class="text-gray-500 dark:text-gray-400"></span>
                </div>

                @if (! empty($detail['breakdown']))
                    <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                        @foreach ($detail['breakdown'] as $row)
                            <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
                                <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ $row['label'] }}
                                </div>
                                <div class="mt-1 font-mono text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">
                                    {{ $row['count'] }}
                                </div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $row['percentage'] }}%
                                </div>
                                @if ($row['meta'])
                                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ $row['meta'] }}
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                @if (! empty($detail['trend']))
                    <div class="mt-6">
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Trend (last 12 weeks)</h3>
                        <div
                            @if (\Filament\Support\Facades\FilamentView::hasSpaMode())
                                x-load="visible"
                            @else
                                x-load
                            @endif
                            x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
                            wire:ignore
                            x-data="chart({
                                cachedData: @js($detail['trend']),
                                options: @js(['scales' => ['y' => ['ticks' => ['stepSize' => 1]]]]),
                                type: 'line',
                            })"
                            class="mt-2 fi-color-custom"
                        >
                            <canvas x-ref="canvas" style="max-height: 16rem"></canvas>
                            <span x-ref="backgroundColorElement" class="text-custom-50 dark:text-custom-400/10" @style([\Filament\Support\get_color_css_variables('primary', shades: [50, 400], alias: 'widgets::chart-widget.background')])></span>
                            <span x-ref="borderColorElement" class="text-custom-500 dark:text-custom-400" @style([\Filament\Support\get_color_css_variables('primary', shades: [400, 500], alias: 'widgets::chart-widget.border')])></span>
                            <span x-ref="gridColorElement" class="text-gray-200 dark:text-gray-800"></span>
                            <span x-ref="textColorElement" class="text-gray-500 dark:text-gray-400"></span>
                        </div>
                    </div>
                @endif

                @if (! empty($detail['table']))
                    <div class="mt-6 overflow-x-auto">
                        <h3 class="mb-2 text-sm font-semibold text-gray-950 dark:text-white">Per-period numbers</h3>
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                                    <th class="py-1.5 pe-4">Period</th>
                                    @if (isset($detail['table'][0]['value']))
                                        <th class="py-1.5 pe-4">{{ $detail['tableValueLabel'] ?? 'Value' }}</th>
                                    @else
                                        <th class="py-1.5 pe-4">Leads Created</th>
                                        <th class="py-1.5 pe-4">Proposals Created</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($detail['table'] as $row)
                                    <tr class="border-b border-gray-100 dark:border-white/5">
                                        <td class="py-1.5 pe-4 text-gray-950 dark:text-white">{{ $row['period'] }}</td>
                                        @if (isset($row['value']))
                                            <td class="py-1.5 pe-4 font-mono tabular-nums text-gray-950 dark:text-white">{{ $row['value'] }}</td>
                                        @else
                                            <td class="py-1.5 pe-4 font-mono tabular-nums text-gray-950 dark:text-white">{{ $row['leadsCreated'] }}</td>
                                            <td class="py-1.5 pe-4 font-mono tabular-nums text-gray-950 dark:text-white">{{ $row['proposalsCreated'] }}</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if (! empty($detail['listItems']))
                    <div class="mt-6">
                        <div class="mb-2 flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $detail['listHeading'] }}</h3>

                            @if (! empty($detail['listUrl']))
                                <a href="{{ $detail['listUrl']['url'] }}" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">
                                    {{ $detail['listUrl']['label'] }}
                                </a>
                            @endif
                        </div>

                        <ul class="divide-y divide-gray-100 rounded-lg border border-gray-200 dark:divide-white/5 dark:border-white/10">
                            @foreach ($detail['listItems'] as $item)
                                <li>
                                    <a href="{{ $item['url'] }}" class="flex items-center justify-between px-4 py-2.5 text-sm hover:bg-gray-50 dark:hover:bg-white/5">
                                        <span class="font-medium text-gray-950 dark:text-white">{{ $item['label'] }}</span>
                                        <span class="text-gray-500 dark:text-gray-400">{{ $item['sublabel'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endif
        </div>

        <div class="px-6 py-12 text-center text-sm text-gray-500 dark:text-gray-400" wire:loading wire:target="open">
            Loading…
        </div>
    </div>
    </div>
</template>
</div>
