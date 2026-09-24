{{--
    Full page layout: Period/Employee filters → the tabbed infolist
    (Overview + one tab per activity type — Call Records, Follow-Ups,
    Appointments, Leads, Demos, Proposals). A custom view rather than
    composing getHeader() individually, so this ordering is explicit and
    doesn't fight Filament's own page template — see ViewProspect's class
    docblock for why getHeader() specifically isn't usable here.

    Filters sit ABOVE the tab bar, not literally "below" it: Filament's
    Infolists\Components\Tabs has no slot between its label row and each
    tab's own content — see ViewProspect's class docblock for why this
    placement is functionally identical to "below the row" (always
    visible regardless of active tab, governs every activity tab, no
    effect on Overview) without overriding a vendor view for pixel-exact
    positioning.

    getFooterWidgets() is gone from this page entirely (Phase:
    tab-restructure Step 2) — every one of the six activity tables now
    mounts as an Infolists\Components\Livewire entry inside its own Tab,
    so there is nothing left for Filament's own page template to render
    as a footer widget. Do NOT reintroduce a getFooterWidgets() override
    or a manual <x-filament-widgets::widgets .../> call here without
    re-reading why the old dual-rendering setup broke Period/Employee
    reactivity (duplicate Livewire keys from
    "{$widgetClass}-{$widgetKey}" — see vendor/filament/widgets/
    resources/views/components/widgets.blade.php) — the same class of bug
    could resurface if a table is ever mounted both as a footer widget
    and inside a tab at once.
--}}
<x-filament-panels::page>
    <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        {{ $this->getFiltersForm() }}
    </div>

    <div wire:key="{{ $this->getId() }}.infolist">
        {{ $this->infolist }}
    </div>
</x-filament-panels::page>
