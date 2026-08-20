<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\CallRecordResource;
use App\Models\Prospect;
use App\Support\DashboardPeriod;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * One of the five mini-tables on the Prospect View page (see
 * ViewProspect::getFooterWidgets()) — this company's Call Records only,
 * reusing CallRecordResource::columns() (minus the Company column, which
 * is redundant here since the whole page is already scoped to one
 * company) so this can never drift from the resource's own main list.
 * $record is auto-injected by ViewRecord's own getWidgetData(); Period +
 * (admin-only) Employee come from the page's shared filters form via
 * InteractsWithPageFilters, the same mechanism KpiBand already uses on the
 * dashboards.
 */
class ProspectCallRecordsTable extends BaseWidget
{
    use InteractsWithPageFilters;

    public ?Prospect $record = null;

    protected int|string|array $columnSpan = 'full';

    // These ARE the page's main content, not a below-the-fold nicety, so
    // there's no reason to make the user wait on a second request to see
    // them — and it removes one extra hop from the already
    // cross-component reactive chain the Period/Employee filters rely on.
    protected static bool $isLazy = false;

    /**
     * $filters is reactive (see InteractsWithPageFilters), but Filament's
     * Table has its own internal caching — nothing about a sibling
     * property changing tells it to re-query on its own. resetTable() is
     * the same mechanism Filament's own table filters use internally
     * (HasFilters::updatedTableFilters()) for a table's *own* filters,
     * applied here since these filters live on the parent page instead.
     */
    public function updatedFilters(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        [$from, $until] = DashboardPeriod::resolve($this->filters);
        $employeeId = $this->filters['employee_id'] ?? null;

        return $table
            ->heading("Call Records — {$this->record?->company_name}")
            ->query(
                CallRecordResource::getEloquentQuery()
                    ->where('prospect_id', $this->record?->id)
                    ->when($employeeId, fn (Builder $q) => $q->where('user_id', $employeeId))
                    ->when($from, fn (Builder $q) => $q->where('called_at', '>=', $from))
                    ->when($until, fn (Builder $q) => $q->where('called_at', '<=', $until))
            )
            ->columns(
                collect(CallRecordResource::columns())
                    ->reject(fn (Tables\Columns\Column $column) => $column->getName() === 'prospect.company_name')
                    ->values()
                    ->all()
            )
            // No column here is searchable once Company (the only one
            // that ever was) is excluded above — except Follow-Ups'
            // `reason`, which is why that one table alone had a search
            // bar. Disabling search at the table level, not per column,
            // means a resource adding a new searchable column later can't
            // silently reintroduce one here — there's nothing to search
            // by when every row is already this one company anyway.
            ->searchable(false)
            ->defaultSort('called_at', 'desc')
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('No calls logged for this company yet.')
            ->emptyStateIcon('heroicon-o-phone');
    }
}
