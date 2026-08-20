{{--
    Period + (admin-only) Employee filters driving the five mini-tables
    below (see ViewProspect::getFooterWidgets()) — rendered here via
    getHeader() rather than as a widget, since the form itself needs to
    live on this page's own Livewire component for its state changes to
    reach $this->filters, which the mini-table widgets then pick up via
    InteractsWithPageFilters (same mechanism KpiBand already uses on the
    dashboards).
--}}
<div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    {{ $this->getFiltersForm() }}
</div>
