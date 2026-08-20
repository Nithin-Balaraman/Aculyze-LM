{{--
    Full page layout: details (collapsed infolist) → Period/Employee
    filters → the five mini-tables. A custom view rather than composing
    getHeader()/getFooterWidgets() piecemeal, so this ordering is explicit
    and doesn't fight Filament's own page template — see ViewProspect's
    class docblock for why getHeader() specifically isn't usable here.
--}}
<x-filament-panels::page>
    <div wire:key="{{ $this->getId() }}.infolist">
        {{ $this->infolist }}
    </div>

    <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        {{ $this->getFiltersForm() }}
    </div>

    <x-filament-widgets::widgets
        :columns="$this->getFooterWidgetsColumns()"
        :data="$this->getWidgetData()"
        :widgets="$this->getVisibleFooterWidgets()"
    />
</x-filament-panels::page>
