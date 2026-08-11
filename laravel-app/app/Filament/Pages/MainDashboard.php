<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\GrowthTrendChart;
use App\Filament\Widgets\LeadsByTemperatureChart;
use App\Filament\Widgets\OrgStatsOverview;
use App\Filament\Widgets\StaleLeadsTable;
use App\Filament\Widgets\StaleProposalsTable;
use App\Support\DashboardPeriod;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\Widget;

/**
 * Admin-only, company-wide dashboard aggregating activity across every
 * employee (AGENTS.md section 31). Access is enforced in mount() — not just
 * hidden from navigation — so a non-admin hitting the URL directly still
 * gets a 403 (AGENTS.md section 39).
 */
class MainDashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationLabel = 'Main Dashboard';

    protected static ?string $title = 'Main Dashboard';

    protected static string $routePath = '/main-dashboard';

    protected static ?int $navigationSort = -1;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

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
            OrgStatsOverview::class,
            LeadsByTemperatureChart::class,
            GrowthTrendChart::class,
            StaleLeadsTable::class,
            StaleProposalsTable::class,
        ];
    }

    public function getColumns(): int|string|array
    {
        return 2;
    }
}
