<?php

namespace App\Filament\Widgets;

use App\Enums\LeadTemperature;
use App\Models\Appointment;
use App\Models\CallRecord;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\User;
use App\Support\DashboardPeriod;
use Carbon\Carbon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;

/**
 * UX Fixes Batch Issue 6: a denser, hero-number KPI band replacing the old
 * OrgStatsOverview/EmployeeStatsOverview plain stat-card widgets — reused
 * across Main Dashboard (employeeId left null -> company-wide), My
 * Dashboard, and the per-employee dashboard (employeeId set), same pattern
 * as LeadsByStageChart.
 *
 * Every number here comes from real, already-scoped queries — no
 * fabricated trend percentages. When the selected period has no
 * well-defined "previous period" to compare against (All Time, or an
 * open-ended custom range), the trend is simply omitted rather than faked.
 * Each tile's 7-day sparkline is one grouped-by-day query, not seven
 * separate counts, to keep the widget's query count small.
 */
class KpiBand extends Widget
{
    use InteractsWithPageFilters;

    protected static string $view = 'filament.widgets.kpi-band';

    protected int|string|array $columnSpan = 'full';

    public ?int $employeeId = null;

    /**
     * @return array<int, array{label: string, icon: string, value: int, delta: ?int, sparkline: array<int, int>, context: ?string}>
     */
    public function getTiles(): array
    {
        [$from, $until] = DashboardPeriod::resolve($this->filters);
        [$prevFrom, $prevUntil] = DashboardPeriod::previous($this->filters);

        $leadsBase = $this->scoped(Lead::query(), 'assigned_to');
        $hotLeads = (clone $leadsBase)
            ->where('temperature', LeadTemperature::Hot)
            ->when($from, fn (Builder $q) => $q->where('created_at', '>=', $from))
            ->when($until, fn (Builder $q) => $q->where('created_at', '<=', $until))
            ->count();

        return [
            $this->buildTile('Calls', 'heroicon-o-phone', CallRecord::query(), 'user_id', 'called_at', $from, $until, $prevFrom, $prevUntil),
            $this->buildTile('Appointments', 'heroicon-o-calendar-days', Appointment::query(), 'assigned_to', 'created_at', $from, $until, $prevFrom, $prevUntil),
            $this->buildTile('Leads', 'heroicon-o-fire', Lead::query(), 'assigned_to', 'created_at', $from, $until, $prevFrom, $prevUntil, $hotLeads > 0 ? "{$hotLeads} hot" : null),
            $this->buildTile('Proposals', 'heroicon-o-document-text', Proposal::query(), 'assigned_to', 'created_at', $from, $until, $prevFrom, $prevUntil),
        ];
    }

    public function getHeading(): string
    {
        return $this->employeeId ? $this->employeeLabel() : 'Organization';
    }

    private function employeeLabel(): string
    {
        if ($this->employeeId === auth()->id()) {
            return 'You';
        }

        return User::query()->find($this->employeeId)?->name ?? 'Employee';
    }

    private function scoped(Builder $query, string $ownerColumn): Builder
    {
        return $this->employeeId ? $query->where($ownerColumn, $this->employeeId) : $query;
    }

    /**
     * @return array{label: string, icon: string, value: int, delta: ?int, sparkline: array<int, int>, context: ?string}
     */
    private function buildTile(
        string $label,
        string $icon,
        Builder $baseQuery,
        string $ownerColumn,
        string $dateColumn,
        ?Carbon $from,
        ?Carbon $until,
        ?Carbon $prevFrom,
        ?Carbon $prevUntil,
        ?string $context = null,
    ): array {
        $value = $this->scoped(clone $baseQuery, $ownerColumn)
            ->when($from, fn (Builder $q) => $q->where($dateColumn, '>=', $from))
            ->when($until, fn (Builder $q) => $q->where($dateColumn, '<=', $until))
            ->count();

        $delta = null;

        if ($prevFrom && $prevUntil) {
            $previous = $this->scoped(clone $baseQuery, $ownerColumn)
                ->where($dateColumn, '>=', $prevFrom)
                ->where($dateColumn, '<=', $prevUntil)
                ->count();

            if ($previous > 0) {
                $delta = (int) round((($value - $previous) / $previous) * 100);
            }
        }

        $sparklineStart = Date::now()->subDays(6)->startOfDay();
        $dailyCounts = $this->scoped(clone $baseQuery, $ownerColumn)
            ->where($dateColumn, '>=', $sparklineStart)
            ->selectRaw("DATE({$dateColumn}) as d, count(*) as c")
            ->groupBy('d')
            ->pluck('c', 'd');

        $sparkline = collect(range(6, 0))
            ->map(fn (int $daysAgo) => (int) ($dailyCounts[Date::now()->subDays($daysAgo)->format('Y-m-d')] ?? 0))
            ->values()
            ->all();

        return [
            'label' => $label,
            'icon' => $icon,
            'value' => $value,
            'delta' => $delta,
            'sparkline' => $sparkline,
            'context' => $context,
        ];
    }
}
