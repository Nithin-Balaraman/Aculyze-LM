{{--
    A near-identical copy of vendor/filament/widgets/resources/views/
    chart-widget.blade.php (same heading/description/filters/chart Alpine
    component), wrapped in a clickable trigger that opens
    App\Filament\Widgets\ChartDetailModal's large detail view with a CSS
    transform "zoom from this card's on-screen position" animation.

    The click handler does two things on click, deliberately not waiting
    on each other:
    1. A plain `window.dispatchEvent(new CustomEvent('chart-detail-open', ...))`
       carrying this card's own bounding rect — ChartDetailModal's own
       Alpine code (resources/views/filament/widgets/chart-detail-modal.
       blade.php) listens for this directly (not through Livewire) so the
       zoom animation starts immediately, without waiting on a server
       round trip.
    2. The same event, because it's a plain browser CustomEvent, is ALSO
       picked up by Livewire's own global-event-to-PHP-listener bridge
       (see vendor/livewire/livewire/js/features/supportListeners.js —
       the same mechanism the reliability notifications' research
       discovered) and calls ChartDetailModal's `#[On('chart-detail-open')]`
       PHP method, which computes and renders the actual chart/breakdown
       content moments later. The modal shows a brief loading state
       between (1) and (2) resolving — see the modal view for that.

    The x-data/x-on:click below live directly on <x-filament-widgets::
    widget> itself (as plain extra HTML attributes, merged onto its root
    element same as `class`) rather than on a wrapping <div> around it —
    that component's own view (vendor/filament/widgets/resources/views/
    components/widget.blade.php) is what actually renders the grid-column
    element carrying columnSpan (e.g. 'full' for the trend charts); an
    outer wrapper div would become the real grid item instead, with no
    span styling of its own, silently breaking full-width widgets down to
    a single grid column. Confirmed live: this was a real regression
    caught during verification, not a hypothetical.
--}}
@php
    use Filament\Support\Facades\FilamentView;
    use Illuminate\Support\Js;

    $color = $this->getColor();
    $heading = $this->getHeading();
    $description = $this->getDescription();
    $filters = $this->getFilters();

    // @js(...) doesn't reliably expand when embedded mid-string inside a
    // Blade *component* tag's plain attribute value (confirmed live: it
    // was left completely unevaluated, literal "@js(...)" text, in the
    // rendered HTML) — computing the JSON here and interpolating via
    // plain {{ }} (which Blade's component-tag compiler does handle
    // correctly) avoids that.
    $chartKeyJson = Js::from($this->getChartKey());
    $chartEmployeeIdJson = Js::from($this->getChartEmployeeId());
@endphp

<x-filament-widgets::widget
    x-data="{}"
    x-on:click="
        if ($event.target.closest('select')) return;
        const rect = $el.getBoundingClientRect();
        window.dispatchEvent(new CustomEvent('chart-detail-open', {
            detail: {
                chartKey: {{ $chartKeyJson }},
                employeeId: {{ $chartEmployeeIdJson }},
                rect: { top: rect.top, left: rect.left, width: rect.width, height: rect.height },
            },
        }));
    "
    class="fi-wi-chart fi-clickable-chart-widget cursor-pointer"
>
        <x-filament::section :description="$description" :heading="$heading">
            @if ($filters)
                <x-slot name="headerEnd">
                    <x-filament::input.wrapper
                        inline-prefix
                        wire:target="filter"
                        class="w-max sm:-my-2"
                    >
                        <x-filament::input.select
                            inline-prefix
                            wire:model.live="filter"
                        >
                            @foreach ($filters as $value => $label)
                                <option value="{{ $value }}">
                                    {{ $label }}
                                </option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </x-slot>
            @endif

            <div
                @if ($pollingInterval = $this->getPollingInterval())
                    wire:poll.{{ $pollingInterval }}="updateChartData"
                @endif
            >
                <div
                    @if (FilamentView::hasSpaMode())
                        x-load="visible"
                    @else
                        x-load
                    @endif
                    x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
                    wire:ignore
                    x-data="chart({
                                cachedData: @js($this->getCachedData()),
                                options: @js($this->getOptions()),
                                type: @js($this->getType()),
                            })"
                    @class([
                        match ($color) {
                            'gray' => null,
                            default => 'fi-color-custom',
                        },
                        is_string($color) ? "fi-color-{$color}" : null,
                    ])
                >
                    <canvas
                        x-ref="canvas"
                        @if ($maxHeight = $this->getMaxHeight())
                            style="max-height: {{ $maxHeight }}"
                        @endif
                    ></canvas>

                    <span
                        x-ref="backgroundColorElement"
                        @class([
                            match ($color) {
                                'gray' => 'text-gray-100 dark:text-gray-800',
                                default => 'text-custom-50 dark:text-custom-400/10',
                            },
                        ])
                        @style([
                            \Filament\Support\get_color_css_variables(
                                $color,
                                shades: [50, 400],
                                alias: 'widgets::chart-widget.background',
                            ) => $color !== 'gray',
                        ])
                    ></span>

                    <span
                        x-ref="borderColorElement"
                        @class([
                            match ($color) {
                                'gray' => 'text-gray-400',
                                default => 'text-custom-500 dark:text-custom-400',
                            },
                        ])
                        @style([
                            \Filament\Support\get_color_css_variables(
                                $color,
                                shades: [400, 500],
                                alias: 'widgets::chart-widget.border',
                            ) => $color !== 'gray',
                        ])
                    ></span>

                    <span
                        x-ref="gridColorElement"
                        class="text-gray-200 dark:text-gray-800"
                    ></span>

                    <span
                        x-ref="textColorElement"
                        class="text-gray-500 dark:text-gray-400"
                    ></span>
                </div>
            </div>
        </x-filament::section>
</x-filament-widgets::widget>
