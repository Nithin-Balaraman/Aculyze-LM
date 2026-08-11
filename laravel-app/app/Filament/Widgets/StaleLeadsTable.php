<?php

namespace App\Filament\Widgets;

use App\Models\Lead;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stale Lead alert (30+ days without stage movement — AGENTS.md section
 * 23). Reused on both the per-employee dashboard (employeeId set) and the
 * admin Main Dashboard (employeeId left null -> company-wide).
 */
class StaleLeadsTable extends BaseWidget
{
    public ?int $employeeId = null;

    protected static ?string $heading = 'Stale Leads (30+ days without movement)';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Lead::query()
                    ->stale()
                    ->when($this->employeeId, fn (Builder $q) => $q->where('assigned_to', $this->employeeId))
            )
            ->columns([
                Tables\Columns\TextColumn::make('prospect.company_name')->label('Company'),
                Tables\Columns\TextColumn::make('stage')->badge(),
                Tables\Columns\TextColumn::make('temperature')->badge(),
                Tables\Columns\TextColumn::make('assignedEmployee.name')
                    ->label('Employee')
                    ->visible(fn () => $this->employeeId === null),
                Tables\Columns\TextColumn::make('stage_changed_at')
                    ->label('Stuck Since')
                    ->dateTime('d M Y'),
            ])
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('No stale leads')
            ->emptyStateDescription('Every active Lead has moved stage within the last 30 days.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }

    public static function canView(): bool
    {
        return true;
    }
}
