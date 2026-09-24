<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\DemoResource;
use App\Models\Prospect;
use App\Support\DashboardPeriod;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * One of six activity tabs on the Prospect View page (see
 * ViewProspect::infolist(), mounted via Filament\Infolists\Components\
 * Livewire inside its own Tab) — this company's Demos only, reusing
 * DemoResource::columns() (minus Company). Demo belongs directly to
 * Prospect (demos.prospect_id, confirmed against the actual migration —
 * it is NOT reached only via Lead, despite also carrying a lead_id), so
 * this filters by prospect_id directly, the same as every other one of
 * these six widgets. See ProspectCallRecordsTable's docblock for the
 * shared record/filters mechanism.
 */
class ProspectDemosTable extends BaseWidget
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
            ->heading("Demos — {$this->record?->company_name}")
            ->query(
                DemoResource::getEloquentQuery()
                    ->where('prospect_id', $this->record?->id)
                    ->when($employeeId, fn (Builder $q) => $q->where('assigned_to', $employeeId))
                    ->when($from, fn (Builder $q) => $q->where('created_at', '>=', $from))
                    ->when($until, fn (Builder $q) => $q->where('created_at', '<=', $until))
            )
            ->columns(
                collect(DemoResource::columns())
                    ->reject(fn (Tables\Columns\Column $column) => $column->getName() === 'lead.prospect.company_name')
                    ->values()
                    ->all()
            )
            ->searchable(false)
            ->defaultSort('demo_at', 'desc')
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('No demos for this company yet.')
            ->emptyStateIcon('heroicon-o-presentation-chart-bar');
    }
}
