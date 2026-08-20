<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AppointmentResource;
use App\Models\Prospect;
use App\Support\DashboardPeriod;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * One of the five mini-tables on the Prospect View page (see
 * ViewProspect::getFooterWidgets()) — this company's Appointments only,
 * reusing AppointmentResource::columns() (minus Company). See
 * ProspectCallRecordsTable's docblock for the shared record/filters
 * mechanism.
 */
class ProspectAppointmentsTable extends BaseWidget
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
            ->heading("Appointments — {$this->record?->company_name}")
            ->query(
                AppointmentResource::getEloquentQuery()
                    ->where('prospect_id', $this->record?->id)
                    ->when($employeeId, fn (Builder $q) => $q->where('assigned_to', $employeeId))
                    ->when($from, fn (Builder $q) => $q->where('created_at', '>=', $from))
                    ->when($until, fn (Builder $q) => $q->where('created_at', '<=', $until))
            )
            ->columns(
                collect(AppointmentResource::columns())
                    ->reject(fn (Tables\Columns\Column $column) => $column->getName() === 'prospect.company_name')
                    ->values()
                    ->all()
            )
            ->searchable(false)
            ->defaultSort('appointment_at', 'asc')
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('No appointments for this company yet.')
            ->emptyStateIcon('heroicon-o-calendar-days');
    }
}
