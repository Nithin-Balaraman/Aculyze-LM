{{--
    The dashboard's opening greeting — see App\Filament\Widgets\
    DashboardGreeting for the time-of-day/name/subtitle logic. Pure
    display; no interaction of its own.
--}}
<x-filament-widgets::widget class="fi-dashboard-greeting">
    <div>
        <p class="fi-dashboard-greeting-date text-xs font-semibold uppercase tracking-widest">
            {{ $this->getDateLine() }}
        </p>

        <h1 class="fi-dashboard-greeting-heading mt-1 text-2xl font-bold text-gray-950 dark:text-white sm:text-3xl">
            {{ $this->getGreeting() }}
        </h1>

        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            {{ $this->getSubtitle() }}
        </p>
    </div>
</x-filament-widgets::widget>
