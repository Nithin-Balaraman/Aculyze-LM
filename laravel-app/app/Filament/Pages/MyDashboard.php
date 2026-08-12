<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\EmployeeStatsOverview;
use App\Filament\Widgets\LeadsByStageChart;
use App\Filament\Widgets\StaleLeadsTable;
use App\Filament\Widgets\StaleProposalsTable;
use App\Support\DashboardPeriod;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Widgets\Widget;

/**
 * The default panel landing page for every logged-in user (Admin or
 * Employee). Since Admin is also an active salesperson (AGENTS.md section
 * 7), this always shows the *logged-in user's own* personal sales metrics —
 * separate from the admin-only, company-wide Main Dashboard.
 */
class MyDashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static string $routePath = '/';

    protected static ?string $navigationLabel = 'My Dashboard';

    protected static ?string $title = 'My Dashboard';

    protected static ?int $navigationSort = -2;

    public function filtersForm(Form $form): Form
    {
        return $form->schema([
            Select::make('period')
                ->label('Period')
                ->options(DashboardPeriod::options())
                ->default('all_time')
                ->live(),
            DatePicker::make('from')
                ->visible(fn (Get $get) => $get('period') === 'custom'),
            DatePicker::make('until')
                ->visible(fn (Get $get) => $get('period') === 'custom'),
        ]);
    }

    /**
     * @return array<class-string<Widget>>
     */
    public function getWidgets(): array
    {
        return [
            EmployeeStatsOverview::class,
            LeadsByStageChart::class,
            StaleLeadsTable::class,
            StaleProposalsTable::class,
        ];
    }

    public function getWidgetData(): array
    {
        return [
            'employeeId' => auth()->id(),
        ];
    }
}
