<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\FollowUpResource;
use App\Models\Prospect;
use App\Support\DashboardPeriod;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * One of the five mini-tables on the Prospect View page (see
 * ViewProspect::getFooterWidgets()) — this company's Follow-Ups only,
 * reusing FollowUpResource::columns() (minus Company). See
 * ProspectCallRecordsTable's docblock for the shared record/filters
 * mechanism.
 */
class ProspectFollowUpsTable extends BaseWidget
{
    use InteractsWithPageFilters;

    public ?Prospect $record = null;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public function updatedFilters(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        [$from, $until] = DashboardPeriod::resolve($this->filters);
        $employeeId = $this->filters['employee_id'] ?? null;

        return $table
            ->heading("Follow-Ups — {$this->record?->company_name}")
            ->query(
                FollowUpResource::getEloquentQuery()
                    ->where('prospect_id', $this->record?->id)
                    ->when($employeeId, fn (Builder $q) => $q->where('user_id', $employeeId))
                    ->when($from, fn (Builder $q) => $q->where('created_at', '>=', $from))
                    ->when($until, fn (Builder $q) => $q->where('created_at', '<=', $until))
            )
            ->columns(
                collect(FollowUpResource::columns())
                    ->reject(fn (Tables\Columns\Column $column) => $column->getName() === 'prospect.company_name')
                    ->values()
                    ->all()
            )
            // This resource's `reason` column is searchable() on its own
            // main list — the only one of the five whose non-Company
            // columns still had a searchable one, which is why this was
            // the only mini-table with a search bar. Disabled at the
            // table level (not per column) so it can't come back if a
            // resource adds a new searchable column later.
            ->searchable(false)
            ->defaultSort('follow_up_at', 'asc')
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('No follow-ups for this company yet.')
            ->emptyStateIcon('heroicon-o-arrow-path');
    }
}
