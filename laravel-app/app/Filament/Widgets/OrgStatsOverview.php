<?php

namespace App\Filament\Widgets;

use App\Enums\LeadTemperature;
use App\Models\CallRecord;
use App\Models\Lead;
use App\Models\Proposal;
use App\Support\DashboardPeriod;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Company-wide metrics for the admin-only Main Dashboard (AGENTS.md
 * section 31). Aggregates activity across every employee.
 */
class OrgStatsOverview extends BaseWidget
{
    use InteractsWithPageFilters;

    protected function period(Builder $query, string $dateColumn): Builder
    {
        [$from, $until] = DashboardPeriod::resolve($this->filters);

        return $query
            ->when($from, fn (Builder $q) => $q->where($dateColumn, '>=', $from))
            ->when($until, fn (Builder $q) => $q->where($dateColumn, '<=', $until));
    }

    protected function getStats(): array
    {
        $calls = $this->period(CallRecord::query(), 'called_at')->count();
        $appointments = $this->period(\App\Models\Appointment::query(), 'created_at')->count();

        $leadsQuery = fn () => $this->period(Lead::query(), 'created_at');
        $totalLeads = $leadsQuery()->count();
        $hotLeads = $leadsQuery()->where('temperature', LeadTemperature::Hot)->count();
        $warmLeads = $leadsQuery()->where('temperature', LeadTemperature::Warm)->count();
        $coldLeads = $leadsQuery()->where('temperature', LeadTemperature::Cold)->count();

        $proposals = $this->period(Proposal::query(), 'created_at')->count();

        return [
            Stat::make('Total Calls', $calls)->icon('heroicon-o-phone'),
            Stat::make('Total Appointments', $appointments)->icon('heroicon-o-calendar-days'),
            Stat::make('Total Leads', $totalLeads)->icon('heroicon-o-fire'),
            // Kept deliberately neutral, not coral/gold/slateblue — those
            // colors are reserved for the Lead temperature badges and the
            // Pipeline Pulse widget so they keep their meaning.
            Stat::make('Hot Leads', $hotLeads)->icon('heroicon-o-fire'),
            Stat::make('Warm Leads', $warmLeads)->icon('heroicon-o-sun'),
            Stat::make('Cold Leads', $coldLeads)->icon('heroicon-o-cloud'),
            Stat::make('Total Proposals', $proposals)->icon('heroicon-o-document-text'),
        ];
    }
}
