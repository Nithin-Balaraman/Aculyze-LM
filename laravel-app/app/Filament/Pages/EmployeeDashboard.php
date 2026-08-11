<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\EmployeeStatsOverview;
use App\Filament\Widgets\StaleLeadsTable;
use App\Filament\Widgets\StaleProposalsTable;
use App\Models\User;
use App\Support\DashboardPeriod;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Widgets\Widget;
use Illuminate\Contracts\Support\Htmlable;

/**
 * One dynamic route that can display the dashboard for any employee, given
 * their user ID — never a hard-coded per-person page (AGENTS.md section 8).
 * Reachable via the "Dashboard" row action on Employee Management. Not
 * shown in the main navigation since it always requires a specific
 * employee.
 *
 * Authorization: Admin may view any employee's dashboard; an Employee may
 * only view their own (mirrors "My Dashboard", just reachable by ID) — this
 * is enforced in mount(), not only by hiding the link (AGENTS.md section 8).
 */
class EmployeeDashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static string $routePath = '/employee-dashboard/{employee}';

    protected static bool $shouldRegisterNavigation = false;

    public User $employee;

    /**
     * Livewire implicitly resolves the route-bound $employee straight into
     * the typed public property above from the {employee} route segment
     * (404s automatically if the ID doesn't exist), so this only needs to
     * enforce authorization.
     */
    public function mount(): void
    {
        abort_unless(
            auth()->user()?->isAdmin() || auth()->id() === $this->employee->id,
            403
        );
    }

    public function getTitle(): string|Htmlable
    {
        return "{$this->employee->name}'s Dashboard";
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
            EmployeeStatsOverview::class,
            StaleLeadsTable::class,
            StaleProposalsTable::class,
        ];
    }

    public function getWidgetData(): array
    {
        return [
            'employeeId' => $this->employee->id,
        ];
    }
}
