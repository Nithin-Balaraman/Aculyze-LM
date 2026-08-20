<?php

namespace App\Filament\Resources\ProspectResource\Pages;

use App\Filament\Resources\ProspectResource;
use App\Filament\Widgets\ProspectAppointmentsTable;
use App\Filament\Widgets\ProspectCallRecordsTable;
use App\Filament\Widgets\ProspectFollowUpsTable;
use App\Filament\Widgets\ProspectLeadsTable;
use App\Filament\Widgets\ProspectProposalsTable;
use App\Models\User;
use App\Support\DashboardPeriod;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\View\View;

/**
 * The global search landing page for a company (see ProspectResource::
 * getGlobalSearchResultUrl()) — the existing details (still the disabled
 * form, unchanged) plus five mini-tables below it, one per resource that
 * can reference a Prospect, each scoped to just this company and reusing
 * that resource's own column set (see {Resource}::columns()).
 *
 * Period + Employee drive all five mini-tables together via a single
 * shared $filters array, the same InteractsWithPageFilters mechanism
 * KpiBand already uses on the dashboards — the reactive sync between this
 * page's $filters property and each widget's own is plain Livewire
 * parent/child reactivity (confirmed against Filament's own Dashboard
 * pages: they never pass `filters` through getWidgetData() either), not
 * something wired up manually here.
 */
class ViewProspect extends ViewRecord
{
    protected static string $resource = ProspectResource::class;

    public ?array $filters = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
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
            // Employees only ever see their own records anyway (every
            // {Resource}::getEloquentQuery() already scopes that), so an
            // Employee selector only has a real purpose for Admin, who can
            // see everyone's — same admin-only gating every other
            // employee-scoping control in this app already uses.
            Select::make('employee_id')
                ->label('Employee')
                ->options(fn () => User::query()->pluck('name', 'id'))
                ->searchable()
                ->live()
                ->visible(fn () => auth()->user()->isAdmin()),
        ]);
    }

    /**
     * @return array<int|string, \Filament\Forms\Form>
     */
    protected function getForms(): array
    {
        return [
            ...parent::getForms(),
            'filtersForm' => $this->filtersForm(
                $this->makeForm()->statePath('filters')->live(),
            ),
        ];
    }

    public function getFiltersForm(): Form
    {
        return $this->getForm('filtersForm');
    }

    /**
     * Filament's own Dashboard page (see vendor/filament/filament/
     * resources/views/pages/dashboard.blade.php) explicitly merges
     * `filters` into the widget data it passes down, rather than relying
     * solely on Livewire's parent/child reactive-prop sync — mirrored
     * here for the same reason: it's the proven, working mechanism,
     * not an assumption about implicit reactivity.
     *
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return [
            ...parent::getWidgetData(),
            'filters' => $this->filters,
        ];
    }

    public function getHeader(): ?View
    {
        return view('filament.resources.prospect-resource.pages.view-prospect-filters');
    }

    /**
     * @return array<class-string>
     */
    protected function getFooterWidgets(): array
    {
        return [
            ProspectCallRecordsTable::class,
            ProspectFollowUpsTable::class,
            ProspectAppointmentsTable::class,
            ProspectLeadsTable::class,
            ProspectProposalsTable::class,
        ];
    }

    public function getFooterWidgetsColumns(): int|string|array
    {
        return 1;
    }
}
