<?php

namespace App\Filament\Widgets;

use App\Models\Proposal;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stale Proposal alert (20+ days without stage/outcome movement —
 * AGENTS.md section 27). Reused on both the per-employee dashboard
 * (employeeId set) and the admin Main Dashboard (employeeId left null ->
 * company-wide).
 */
class StaleProposalsTable extends BaseWidget
{
    public ?int $employeeId = null;

    protected static ?string $heading = 'Stale Proposals (20+ days without movement)';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Proposal::query()
                    ->stale()
                    ->when($this->employeeId, fn (Builder $q) => $q->where('assigned_to', $this->employeeId))
            )
            ->columns([
                Tables\Columns\TextColumn::make('prospect.company_name')->label('Company'),
                Tables\Columns\TextColumn::make('stage')->badge(),
                Tables\Columns\TextColumn::make('value')->money('INR')->placeholder('—'),
                Tables\Columns\TextColumn::make('assignedEmployee.name')
                    ->label('Employee')
                    ->visible(fn () => $this->employeeId === null),
                Tables\Columns\TextColumn::make('stage_changed_at')
                    ->label('Stuck Since')
                    ->dateTime('d M Y'),
            ])
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('No stale proposals')
            ->emptyStateDescription('Every open Proposal has moved within the last 20 days.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }

    public static function canView(): bool
    {
        return true;
    }
}
