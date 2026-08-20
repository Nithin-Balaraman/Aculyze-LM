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

    protected static ?string $heading = 'Call Records';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        [$from, $until] = DashboardPeriod::resolve($this->filters);
        $employeeId = $this->filters['employee_id'] ?? null;

        return $table
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
            ->defaultSort('called_at', 'desc')
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('No calls logged for this company yet.')
            ->emptyStateIcon('heroicon-o-phone');
    }
}
