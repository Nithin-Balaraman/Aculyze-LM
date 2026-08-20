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
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

/**
 * The global search landing page for a company (see ProspectResource::
 * getGlobalSearchResultUrl()) — the company's own details (a read-only
 * infolist, collapsed by default; the company name itself is the page's
 * own heading instead, so it stays visible regardless of that collapse
 * state) plus five mini-tables below, one per resource that can reference
 * a Prospect, each scoped to just this company and reusing that
 * resource's own column set (see {Resource}::columns()).
 *
 * Period + Employee drive all five mini-tables together via a single
 * shared $filters array and InteractsWithPageFilters, the same mechanism
 * KpiBand already uses on the dashboards.
 *
 * This page overrides its own Blade view (rather than composing getHeader()
 * /getFooterWidgets() individually) so the layout — details, then filters,
 * then the five tables — is explicit and doesn't fight Filament's page
 * template: getHeader() specifically is a mutually-exclusive alternative to
 * the standard title/breadcrumbs/header-actions bar (see
 * vendor/filament/filament/resources/views/components/page/index.blade.php),
 * so using it to render the filters form here previously made the Edit
 * header action disappear entirely.
 */
class ViewProspect extends ViewRecord
{
    protected static string $resource = ProspectResource::class;

    protected static string $view = 'filament.resources.prospect-resource.pages.view-prospect';

    public ?array $filters = null;

    /**
     * Filament's own Dashboard pages (see vendor/filament/filament/src/
     * Pages/Dashboard/Concerns/HasFilters::mountHasFilters()) explicitly
     * fill their filters form during mount, which is what actually turns
     * $filters from null into a populated array (schema defaults resolved
     * in). Skipping this step — as this page did before — left $filters
     * as raw null client-side: Livewire's JS then threw
     * "Cannot set properties of null" the moment the Period select's
     * wire:model.live tried to write filters.period, silently aborting
     * the request before it was ever sent (so updatedFilters() never
     * fired), and Alpine's entangle() for the Employee select failed
     * outright since filters.employee_id didn't exist on a null filters.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->getFiltersForm()->fill($this->filters);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }

    public function getHeading(): string
    {
        return $this->getRecord()->company_name;
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Company Details')
                    ->collapsible()
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('contact_person')->placeholder('—'),
                        TextEntry::make('designation')->placeholder('—'),
                        TextEntry::make('telephone')->placeholder('—'),
                        TextEntry::make('mobile')->placeholder('—'),
                        TextEntry::make('email')->placeholder('—'),
                        TextEntry::make('website')->placeholder('—'),
                        TextEntry::make('industry')->placeholder('—'),
                        TextEntry::make('source')->placeholder('—'),
                        TextEntry::make('address')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('locality')->placeholder('—'),
                        TextEntry::make('city')->placeholder('—'),
                        TextEntry::make('state')->placeholder('—'),
                        TextEntry::make('pincode')->placeholder('—'),
                        TextEntry::make('assignedEmployee.name')->label('Assigned Employee')->placeholder('Unassigned'),
                        TextEntry::make('notes')->placeholder('—')->columnSpanFull(),
                    ]),
            ]);
    }

    public function filtersForm(Form $form): Form
    {
        return $form
            ->columns(2)
            ->schema([
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
     * @return array<int|string, Form>
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
