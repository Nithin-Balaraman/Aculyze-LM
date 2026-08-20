{{--
    Full page layout: details (collapsed infolist) → Period/Employee
    filters → the five mini-tables. A custom view rather than composing
    getHeader()/getFooterWidgets() piecemeal, so this ordering is explicit
    and doesn't fight Filament's own page template — see ViewProspect's
    class docblock for why getHeader() specifically isn't usable here.

    IMPORTANT: do NOT add a manual <x-filament-widgets::widgets .../> call
    for the footer widgets here. <x-filament-panels::page> IS Filament's
    generic page/index.blade.php template, which already renders
    getVisibleFooterWidgets() itself immediately after this slot, using
    the same getWidgetData()/getFooterWidgetsColumns(). Rendering them
    again here duplicated all five widgets on every page load, and since
    Filament's widgets.blade.php keys each @livewire() call only by
    "{$widgetClass}-{$widgetKey}" (see vendor/filament/widgets/resources/
    views/components/widgets.blade.php), both copies got identical
    Livewire keys — an invalid state that broke the Period/Employee
    filters' reactive DOM updates, not just the visible double-rendering.
--}}
<x-filament-panels::page>
    <div wire:key="{{ $this->getId() }}.infolist">
        {{ $this->infolist }}
    </div>

    <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        {{ $this->getFiltersForm() }}
    </div>
</x-filament-panels::page>
