<?php

namespace App\Filament\Widgets;

use App\Enums\LeadTemperature;
use App\Models\Appointment;
use App\Models\CallRecord;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\User;
use App\Support\DashboardPeriod;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * The required per-employee dashboard metrics from AGENTS.md section 33.
 * Reused for both "My Dashboard" (employeeId left null -> the logged-in
 * user) and the admin-only per-employee dashboard (employeeId set from the
 * route parameter).
 */
class EmployeeStatsOverview extends BaseWidget
{
    use InteractsWithPageFilters;

    public ?int $employeeId = null;

    protected function getEmployee(): User
    {
        return $this->employeeId
            ? User::query()->findOrFail($this->employeeId)
            : auth()->user();
    }

    protected function period(Builder $query, string $dateColumn): Builder
    {
        [$from, $until] = DashboardPeriod::resolve($this->filters);

        return $query
            ->when($from, fn (Builder $q) => $q->where($dateColumn, '>=', $from))
            ->when($until, fn (Builder $q) => $q->where($dateColumn, '<=', $until));
    }

    protected function getStats(): array
    {
        $employee = $this->getEmployee();

        $calls = $this->period(CallRecord::query()->where('user_id', $employee->id), 'called_at')->count();
        $appointments = $this->period(Appointment::query()->where('assigned_to', $employee->id), 'created_at')->count();

        $leadsQuery = fn () => $this->period(Lead::query()->where('assigned_to', $employee->id), 'created_at');
        $totalLeads = $leadsQuery()->count();
        $hotLeads = $leadsQuery()->where('temperature', LeadTemperature::Hot)->count();
        $warmLeads = $leadsQuery()->where('temperature', LeadTemperature::Warm)->count();
        $coldLeads = $leadsQuery()->where('temperature', LeadTemperature::Cold)->count();

        $proposals = $this->period(Proposal::query()->where('assigned_to', $employee->id), 'created_at')->count();

        return [
            Stat::make('Calls Made', $calls)->icon('heroicon-o-phone'),
            Stat::make('Appointments', $appointments)->icon('heroicon-o-calendar-days'),
            Stat::make('Total Leads', $totalLeads)->icon('heroicon-o-fire'),
            // Kept deliberately neutral, not coral/gold/slateblue — those
            // colors are reserved for the Lead temperature badges and the
            // Pipeline Pulse widget so they keep their meaning.
            Stat::make('Hot Leads', $hotLeads)->icon('heroicon-o-fire'),
            Stat::make('Warm Leads', $warmLeads)->icon('heroicon-o-sun'),
            Stat::make('Cold Leads', $coldLeads)->icon('heroicon-o-cloud'),
            Stat::make('Proposals', $proposals)->icon('heroicon-o-document-text'),
        ];
    }
}
