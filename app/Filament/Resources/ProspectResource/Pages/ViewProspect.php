<?php

namespace App\Filament\Resources\ProspectResource\Pages;

use App\Filament\Resources\ProspectResource;
use App\Filament\Widgets\ProspectAppointmentsTable;
use App\Filament\Widgets\ProspectCallRecordsTable;
use App\Filament\Widgets\ProspectDemosTable;
use App\Filament\Widgets\ProspectFollowUpsTable;
use App\Filament\Widgets\ProspectLeadsTable;
use App\Filament\Widgets\ProspectProposalsTable;
use App\Models\User;
use App\Support\DashboardPeriod;
use App\Support\Filament\SectionTabs;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\Livewire as InfolistLivewire;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\Tabs\Tab;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

/**
 * The global search landing page for a company (see ProspectResource::
 * getGlobalSearchResultUrl()) — 7 tabs, matching the pattern already
 * built for Commercial Version (see App\Support\Filament\SectionTabs):
 * Overview | Call Records | Follow-Ups | Appointments | Leads | Demos |
 * Proposals. Reactivity across a tab switch/filter change was spiked
 * first with Call Records alone before the other five were migrated —
 * see git history for that spike's own empirical Playwright verification
 * (Infolists\Components\Livewire's #[Reactive] $filters prop behaves
 * identically to Filament's own widgets-footer mechanism it replaced).
 *
 * Company details are the "Overview" tab's content — EXPANDED by default
 * (the old ->collapsed() only made sense competing with six stacked
 * tables on one page; alone in its own tab, there's nothing to collapse
 * against).
 *
 * Period + Employee drive every activity tab's mini-table together via a
 * single shared $filters array and InteractsWithPageFilters, the same
 * mechanism KpiBand already uses on the dashboards — unchanged by the tab
 * restructure; only how each table is mounted into the page changed (each
 * is now an Infolists\Components\Livewire entry inside its own Tab,
 * rather than a getFooterWidgets() entry — that mechanism is gone from
 * this page entirely now that every table lives in a tab).
 *
 * Each of the six InfolistLivewire entries below carries an explicit,
 * unique ->key(...). Without one, Filament's own Livewire component
 * (vendor/filament/infolists/.../livewire.blade.php) omits the Livewire
 * @livewire key entirely, and Livewire's DOM-morph cannot reliably tell
 * six sibling nested components apart across a parent-triggered re-render
 * (all six share the same property shape — toggledTableColumns,
 * tableRecordsPerPage, etc.). Found only at full 6-widget scale via real
 * Playwright QA (not by the 2-tab spike, and not by any PHPUnit test,
 * since Livewire::test() never exercises a client-side DOM morph): after
 * changing Period/Employee, the active tab's content silently swapped to a
 * different tab's data while the tab label stayed correctly highlighted.
 * Explicit unique keys are the same fix Filament's own widgets-footer
 * mechanism already relied on ("{$widgetClass}-{$widgetKey}").
 *
 * This page overrides its own Blade view (rather than composing getHeader()
 * /getFooterWidgets() individually) so the layout is explicit and doesn't
 * fight Filament's page template: getHeader() specifically is a
 * mutually-exclusive alternative to the standard title/breadcrumbs/
 * header-actions bar (see vendor/filament/filament/resources/views/
 * components/page/index.blade.php), so using it to render the filters
 * form here previously made the Edit header action disappear entirely.
 * The filters form sits directly above the tab bar (Filament's Tabs
 * component has no slot between its label row and each tab's own
 * content, so a literal "between the tab row and the content" placement
 * isn't achievable without overriding a vendor view) — functionally
 * identical either way: always visible regardless of the active tab,
 * governs every activity tab uniformly, has no effect while on Overview.
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
            // See ProspectResource::BACK_BUTTON_CLICK_HANDLER's own
            // docblock: real browser history.back() is the primary
            // mechanism (works correctly however many hops deep), with
            // ->url() to the Prospects list as the fallback for a fresh
            // tab/bookmark or no-JS. ->color(BACK_BUTTON_COLOR) — see that
            // constant's own docblock for why gold specifically, not
            // 'info' (too close to Edit's own blue-family default) or a
            // one-off hex value.
            Actions\Action::make('back')
                ->label('Back')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color(ProspectResource::BACK_BUTTON_COLOR)
                ->url(fn () => ProspectResource::getBackFallbackUrl())
                ->extraAttributes(['x-on:click' => ProspectResource::BACK_BUTTON_CLICK_HANDLER]),
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
                SectionTabs::make('prospect-view-tabs', [
                    Tab::make('Overview')->schema([$this->companyDetailsSection()]),
                    Tab::make('Call Records')->schema([
                        InfolistLivewire::make(ProspectCallRecordsTable::class, ['filters' => $this->filters])
                            ->key('prospect-view-call-records-widget'),
                    ]),
                    Tab::make('Follow-Ups')->schema([
                        InfolistLivewire::make(ProspectFollowUpsTable::class, ['filters' => $this->filters])
                            ->key('prospect-view-follow-ups-widget'),
                    ]),
                    Tab::make('Appointments')->schema([
                        InfolistLivewire::make(ProspectAppointmentsTable::class, ['filters' => $this->filters])
                            ->key('prospect-view-appointments-widget'),
                    ]),
                    Tab::make('Leads')->schema([
                        InfolistLivewire::make(ProspectLeadsTable::class, ['filters' => $this->filters])
                            ->key('prospect-view-leads-widget'),
                    ]),
                    Tab::make('Demos')->schema([
                        InfolistLivewire::make(ProspectDemosTable::class, ['filters' => $this->filters])
                            ->key('prospect-view-demos-widget'),
                    ]),
                    Tab::make('Proposals')->schema([
                        InfolistLivewire::make(ProspectProposalsTable::class, ['filters' => $this->filters])
                            ->key('prospect-view-proposals-widget'),
                    ]),
                ]),
            ]);
    }

    /**
     * ->inlineLabel() is Filament's own built-in label-left/value-right
     * row layout (see vendor/filament/infolists/resources/views/
     * components/entry-wrapper/index.blade.php: a plain CSS grid,
     * sm:grid-cols-3, label in the first column and value spanning the
     * remaining two — no <table>, no visible cell borders). Set once on
     * the Section, it cascades to every child TextEntry automatically via
     * Concerns\HasInlineLabel::hasInlineLabel()'s container/parent
     * fallback chain, so no per-field ->inlineLabel() calls are needed.
     * ->columns(2) is dropped — inlineLabel's own per-row grid already
     * gives each field its two columns (label, value); keeping the
     * Section-level 2-up grid on top of that would have paired two
     * unrelated fields' label/value rows side by side instead of one
     * full-width row per field.
     */
    private function companyDetailsSection(): Section
    {
        return Section::make('Company Details')
            ->inlineLabel()
            ->schema([
                TextEntry::make('contact_person')->placeholder('—'),
                TextEntry::make('designation')->placeholder('—'),
                TextEntry::make('telephone')->placeholder('—'),
                TextEntry::make('mobile')->placeholder('—'),
                TextEntry::make('email')->placeholder('—'),
                TextEntry::make('website')->placeholder('—'),
                TextEntry::make('industry')->placeholder('—'),
                TextEntry::make('source')->placeholder('—'),
                TextEntry::make('address')->placeholder('—'),
                TextEntry::make('locality')->placeholder('—'),
                TextEntry::make('city')->placeholder('—'),
                TextEntry::make('state')->placeholder('—'),
                TextEntry::make('pincode')->placeholder('—'),
                TextEntry::make('assignedEmployee.name')->label('Assigned Employee')->placeholder('Unassigned'),
                TextEntry::make('notes')->placeholder('—'),
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
}
