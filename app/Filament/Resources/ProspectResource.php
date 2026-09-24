<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProspectResource\Pages;
use App\Models\Prospect;
use App\Models\User;
use App\Support\TableBulkActions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The Database module (AGENTS.md section 10) — the master list of
 * companies/contacts. Everything in the sales workflow starts from here.
 */
class ProspectResource extends Resource
{
    protected static ?string $model = Prospect::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Database';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'company_name';

    /**
     * Every ->toggleable() column name below, all with
     * isToggledHiddenByDefault: true (kept in sync with table() by a
     * dedicated test). Used by ListProspects to compute the true default
     * toggle state (every one of these false/hidden) WITHOUT calling
     * $this->getTable() — needed specifically because that default has to
     * be resolved before the table itself has been constructed (see
     * ListProspects::bootedInteractsWithTable()'s own docblock for why).
     *
     * @var array<int, string>
     */
    public const TOGGLEABLE_COLUMNS = [
        'contact_person', 'designation', 'mobile', 'email', 'website',
        'industry', 'source', 'city', 'state', 'locality', 'address',
        'pincode', 'created_at',
    ];

    public static function form(Form $form): Form
    {
        return $form->schema(static::formSchema());
    }

    /**
     * Extracted from form() so other resources can open the exact same
     * Prospect creation fields inline (see CallRecordResource's Company
     * field — the "+ Create new company…" search-dropdown row and the
     * standard createOptionForm "+" button both reuse this).
     *
     * $requireContactFields controls Contact Person/Designation/Email
     * only. They're optional on the standalone Prospect Create/Edit pages
     * (Saji-requested — often genuinely unknown when a company is first
     * added), but the inline "+ Create new company…" modal reached from
     * the Call Record form is a separate, narrower context: a rep is
     * already on the phone with this exact contact, so Saji wants these
     * three captured right then, not left for someone to fill in later —
     * see CallRecordResource::form(), the only caller that passes true.
     *
     * @return array<int, Forms\Components\Component>
     */
    public static function formSchema(bool $requireContactFields = false): array
    {
        return [
            Forms\Components\Section::make('Company')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('company_name')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('contact_person')
                        ->required($requireContactFields)
                        ->maxLength(255),
                    Forms\Components\TextInput::make('designation')
                        ->required($requireContactFields)
                        ->maxLength(255),
                    // Neither is individually mandatory — Saji wants at
                    // least one real phone number on file, not both
                    // required, so each is only required when the OTHER is
                    // blank (mirrors the conditional-required pattern used
                    // throughout this codebase, e.g. FollowUpResource's
                    // outcome-driven fields). ->live(onBlur:) so the
                    // other's required-asterisk updates without hammering
                    // Livewire on every keystroke.
                    Forms\Components\TextInput::make('telephone')
                        ->tel()
                        ->maxLength(20)
                        ->live(onBlur: true)
                        ->required(fn (Forms\Get $get) => blank($get('mobile')))
                        ->validationMessages(['required' => 'Provide at least one of Telephone or Mobile.']),
                    Forms\Components\TextInput::make('mobile')
                        ->tel()
                        ->maxLength(20)
                        ->live(onBlur: true)
                        ->required(fn (Forms\Get $get) => blank($get('telephone')))
                        ->validationMessages(['required' => 'Provide at least one of Telephone or Mobile.']),
                    Forms\Components\TextInput::make('email')
                        ->required($requireContactFields)
                        ->email()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('website')
                        ->required()
                        ->url()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('industry')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('source')
                        ->required()
                        ->maxLength(255),
                ]),
            Forms\Components\Section::make('Location')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('address')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('locality')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('city')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('state')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('pincode')
                        ->required()
                        ->maxLength(20),
                ]),
            Forms\Components\Section::make('Ownership')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('assigned_to')
                        ->label('Assigned Employee')
                        ->relationship('assignedEmployee', 'name')
                        ->default(fn () => auth()->id())
                        ->required()
                        ->searchable()
                        ->preload()
                        // Employees may not hand their own prospects to
                        // someone else — only Admin assigns/reassigns
                        // (AGENTS.md sections 11, 28).
                        ->disabled(fn () => ! auth()->user()->isAdmin())
                        ->dehydrated(),
                ]),
            Forms\Components\Section::make('Notes')
                ->schema([
                    Forms\Components\Textarea::make('notes')
                        ->required()
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            // Company Name search is now BLENDED, not prefix-only: a
            // plain '%term%' WHERE already covers both "starts with" and
            // "contains elsewhere" (a prefix match is just a substring
            // match at position 0), so the WHERE itself didn't need to
            // change from a single LIKE. What changes is ranking —
            // starts-with results must sort before contains-only ones.
            //
            // That ranking CANNOT live inside the column's own
            // ->searchable(query: ...) closure (contrary to how it might
            // look like it should work): Filament calls that closure with
            // the query object from inside a ->where(function ($query) {
            // ... }) group (Concerns\CanSearchRecords::
            // applyGlobalSearchToTableQuery()), and Laravel's own
            // Query\Builder::whereNested()/forNestedWhere() build that
            // group using a completely separate, throwaway Builder
            // instance — addNestedWhereQuery() copies only its `wheres`
            // and bindings back onto the real query, never its `orders`.
            // Confirmed directly (not assumed): calling ->orderByRaw()
            // inside such a closure produces zero ORDER BY in the final
            // ->toSql(), and the real query's ->orders stays null.
            //
            // The ranking is added here instead, via ->modifyQueryUsing(),
            // which runs on the table's real top-level query. Per
            // Concerns\HasRecords::filterTableQuery()/
            // getFilteredSortedTableQuery(), search is applied before
            // sorting (applySearchToTableQuery() then, separately and
            // later, applySortingToTableQuery()) — and CanSortRecords
            // only ever calls ->orderBy() (additive), never ->reorder()
            // (which would wipe prior orders). So an order-by added here,
            // before that later step runs, always ends up FIRST/primary,
            // with the table's defaultSort('updated_at', 'desc') — or
            // whatever column a user has actively clicked to sort by —
            // preserved as the secondary tie-breaker, exactly as before.
            // This also means ranking naturally applies only while a
            // search term is present: with no term, this closure is a
            // no-op and sorting is untouched.
            ->modifyQueryUsing(function (Builder $query, $livewire): Builder {
                $search = $livewire->getTableSearch();

                if (filled($search)) {
                    $query->orderByRaw(
                        'case when company_name like ? then 0 else 1 end',
                        ["{$search}%"],
                    );
                }

                return $query;
            })
            ->columns([
                // Company Name is the only searchable column here. The
                // other 7 columns (contact_person, telephone, email,
                // industry, city, address, locality) intentionally lost
                // ->searchable() here — see this method's own history for
                // why: a plain substring OR-search across all 8 columns
                // made results look unrelated to the query whenever a hit
                // landed in one of the 6 columns hidden by default (e.g.
                // "x" matching the shared "Textiles" industry value). This
                // is scoped to the table's own search box only; the
                // top-nav global search is untouched — it was already
                // company_name-only via $recordTitleAttribute.
                Tables\Columns\TextColumn::make('company_name')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('company_name', 'like', "%{$search}%"))
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('contact_person')
                    ->toggleable(isToggledHiddenByDefault: true),
                // designation/mobile/website/state/pincode/source: genuine
                // gaps found investigating toggle-columns completeness —
                // each is a real, non-computed Prospect column, used on
                // both the create/edit form and ViewProspect's own
                // infolist, with no design reason found to exclude it here
                // (unlike gstin/billing_address/billing_state and the
                // creator relation, which are absent from ViewProspect too
                // and scoped elsewhere — see this method's own history/PR
                // notes). Added alongside their natural companion column,
                // hidden by default like every other toggleable column.
                Tables\Columns\TextColumn::make('designation')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('telephone')
                    ->label('Telephone'),
                Tables\Columns\TextColumn::make('mobile')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('email')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('website')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('industry')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('source')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('city')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('state')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('locality')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('address')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('pincode')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('assignedEmployee.name')
                    ->label('Assigned To')
                    ->badge()
                    ->placeholder('Unassigned')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // Every toggleable column above now has a matching filter that
            // automatically follows its column's toggle state: visible
            // only while the column is shown, via ->visible(fn ($livewire)
            // => ! $livewire->isTableColumnToggledHidden($column)) — the
            // exact same method Filament itself already uses to decide
            // whether the COLUMN is hidden, so this can never drift out of
            // sync with the toggle-columns dropdown. The other half of the
            // "hiding clears the value, not just the control" requirement
            // lives in ListProspects::updatedToggledTableColumns(), which
            // sweeps every toggleable column's filter state the moment it
            // becomes hidden — a filter's ->visible(false) alone would
            // otherwise leave its value silently still in $tableFilters,
            // still affecting the query underneath a control the user can
            // no longer even see.
            //
            // 'assigned_to' is intentionally untouched: it filters the
            // "Assigned To" column, which is NOT toggleable (always
            // visible by design), so it stays admin-only-visible and
            // permanently available regardless of any toggle state — the
            // one "currently-fixed, always-visible filter" this task asked
            // to confirm doesn't conflict with the toggle-following ones.
            ->filters([
                Tables\Filters\SelectFilter::make('assigned_to')
                    ->label('Assigned Employee')
                    ->relationship('assignedEmployee', 'name')
                    ->visible(fn () => auth()->user()->isAdmin()),
                static::categorySelectFilter('industry'),
                static::containsTextFilter('contact_person', 'Contact Person'),
                static::containsTextFilter('designation', 'Designation'),
                static::containsTextFilter('mobile', 'Mobile'),
                static::containsTextFilter('email', 'Email'),
                static::containsTextFilter('website', 'Website'),
                static::categorySelectFilter('source', 'Source'),
                static::categorySelectFilter('city'),
                static::categorySelectFilter('state'),
                static::containsTextFilter('locality', 'Locality'),
                static::containsTextFilter('address', 'Address'),
                static::containsTextFilter('pincode', 'Pincode'),
                Tables\Filters\Filter::make('created_at')
                    ->label('Created At')
                    ->form([
                        Forms\Components\DatePicker::make('created_from')->label('From'),
                        Forms\Components\DatePicker::make('created_until')->label('Until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['created_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['created_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['created_from'] ?? null) {
                            $indicators[] = 'Created from '.\Illuminate\Support\Carbon::parse($data['created_from'])->toFormattedDateString();
                        }

                        if ($data['created_until'] ?? null) {
                            $indicators[] = 'Created until '.\Illuminate\Support\Carbon::parse($data['created_until'])->toFormattedDateString();
                        }

                        return $indicators;
                    })
                    ->visible(static::visibleWhenColumnToggledOn('created_at')),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\Action::make('assign')
                        ->label('Reassign')
                        ->icon('heroicon-o-user-plus')
                        ->color('gray')
                        ->visible(fn (Prospect $record) => auth()->user()->can('assign', $record))
                        ->form([
                            Forms\Components\Select::make('assigned_to')
                                ->label('Assign To')
                                ->options(fn () => User::query()->pluck('name', 'id'))
                                ->required()
                                ->searchable(),
                        ])
                        ->action(function (Prospect $record, array $data) {
                            $record->update(['assigned_to' => $data['assigned_to']]);
                        }),
                    Tables\Actions\DeleteAction::make()
                        ->visible(fn () => auth()->user()->isAdmin()),
                ]),
            ])
            // Delete/Deselect as standalone toolbar buttons, not nested in a
            // "Bulk actions" dropdown — a plain array (no BulkActionGroup
            // wrapper) is exactly what makes Filament render each one as
            // its own directly-visible button instead of collapsing them
            // behind a single dropdown trigger. Neither action's own
            // config (visibility, confirmation dialog, dehydration, etc.)
            // is affected by this — only how they're grouped for display.
            // Prospects-table-only change; every other resource keeps its
            // existing BulkActionGroup([...]) pattern untouched.
            ->bulkActions([
                TableBulkActions::deselectAll(),
                Tables\Actions\DeleteBulkAction::make()
                    ->visible(fn () => auth()->user()->isAdmin()),
            ])
            // Most recently updated first. This also covers "most recently
            // added": a new Prospect's updated_at equals its created_at at
            // the moment of creation, so it lands at the top same as
            // before — but anything later edited (status change, a call
            // logged, details updated) correctly jumps back to the top
            // too, which plain created_at never did.
            ->defaultSort('updated_at', 'desc')
            ->emptyStateHeading('No prospects yet.')
            ->emptyStateDescription('Add a company to start the pipeline — every call starts here.')
            ->emptyStateIcon('heroicon-o-building-office-2');
    }

    /**
     * Shared by every toggle-following filter below: visible only while
     * its own column is currently shown. `$livewire` is auto-injected by
     * Filament's own evaluate() (matched by parameter name, exactly like
     * every other ->visible()/->query() closure already in this file) and
     * exposes isTableColumnToggledHidden() — the same method the toggle
     * columns dropdown itself is driven by, so this can never disagree
     * with what the user actually sees toggled on/off.
     */
    private static function visibleWhenColumnToggledOn(string $column): \Closure
    {
        return fn ($livewire): bool => ! $livewire->isTableColumnToggledHidden($column);
    }

    /**
     * A "contains" text filter for a free-text column (name, address,
     * phone number, …) — mirrors the shape of a plain TextInput search,
     * not an exact match, since none of these columns have a small fixed
     * set of values.
     */
    private static function containsTextFilter(string $column, string $label): Tables\Filters\Filter
    {
        return Tables\Filters\Filter::make($column)
            ->label($label)
            ->form([
                Forms\Components\TextInput::make('value')->label($label),
            ])
            ->query(fn (Builder $query, array $data): Builder => $query->when(
                filled($data['value'] ?? null),
                fn (Builder $q) => $q->where($column, 'like', '%'.$data['value'].'%'),
            ))
            ->indicateUsing(fn (array $data): ?string => filled($data['value'] ?? null) ? "{$label}: {$data['value']}" : null)
            ->visible(static::visibleWhenColumnToggledOn($column));
    }

    /**
     * A Select-from-distinct-values filter for a column that behaves like
     * a category (industry, source, city, state) — the same pattern the
     * pre-existing 'industry' filter already used, just reused here and
     * given the toggle-following ->visible().
     */
    private static function categorySelectFilter(string $column, ?string $label = null): Tables\Filters\SelectFilter
    {
        return Tables\Filters\SelectFilter::make($column)
            ->label($label ?? str($column)->headline())
            ->options(fn () => Prospect::query()->whereNotNull($column)->distinct()->pluck($column, $column))
            ->visible(static::visibleWhenColumnToggledOn($column));
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProspects::route('/'),
            'create' => Pages\CreateProspect::route('/create'),
            'view' => Pages\ViewProspect::route('/{record}'),
            'edit' => Pages\EditProspect::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    /**
     * Global search only — clicking a company from the top-right search bar
     * now lands on the read-only View page (details + the five mini-tables)
     * instead of jumping straight to Edit. This is a completely separate
     * mechanism from the Database list's own row click/->actions(), which
     * are untouched: Filament's default here (see Resource::
     * getGlobalSearchResultUrl()) prefers the 'edit' page whenever the user
     * can edit, which is why search used to skip View entirely.
     */
    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        return static::getUrl('view', ['record' => $record]);
    }

    /**
     * The Back action's color. It previously used 'info' (the same brand
     * cyan token as EditAction's implicit default — 'primary', unset on
     * EditAction, falls through to Filament's own default, confirmed via
     * EditAction::make()->getColor() === null), and cyan sits close
     * enough to primary's steel blue in hue that the two read as "the
     * same kind of button" at a glance — exactly what was reported.
     *
     * Every ->color() already in active use across this app's own
     * Filament actions was tallied before picking a replacement:
     * gray (~12 uses, the established "plain secondary" choice, but
     * rejected here — going back to it would undo the earlier, explicit
     * request to make Back stand out, not blend in), danger/success/
     * warning (Filament's own semantic reds/greens/ambers — wrong meaning
     * for a harmless navigation action), info/primary/accent/slateblue/
     * navy (all blue-family — the exact problem being fixed), and coral
     * (this app's own registered brand token, but already a strong,
     * active "Mark Lost" signal on 2 real buttons — AppointmentResource
     * and LeadResource — reusing it for Back would misleadingly suggest
     * something negative).
     *
     * 'gold' is what's left: a real, already-registered brand color
     * (AdminPanelProvider's colors(), #C99A3D) — not a one-off hex value —
     * warm and clearly distinct in hue from Edit's cool blue, and, unlike
     * coral, never used as an actual action color anywhere in this app
     * today (only as a badge/tag accent), so adopting it here doesn't
     * collide with an existing "this button does X" expectation.
     */
    public const BACK_BUTTON_COLOR = 'gold';

    /**
     * Shared by both pages' "Back" header action: intercepts a plain left-
     * click and, only when the browser tab actually has somewhere to go
     * back TO (history.length !== 1 — 1 means a fresh tab/bookmark, the
     * one case this task asked to still fall back from), replaces it with
     * real history.back(). No existing Filament-Action-plus-Alpine
     * convention for client-side-only navigation exists anywhere in this
     * app (confirmed before writing this) — the closest relative is
     * pipeline-board-card.blade.php's own plain
     * x-on:click="window.location = '...'" for the same kind of
     * programmatic navigation, just via a fixed URL instead of history.
     *
     * Deliberately layered ON TOP of a real ->url() (see
     * getBackFallbackUrl()) rather than replacing it outright:
     * - Left-click with real history present: this handler fires,
     *   preventDefault() stops the plain link navigation, history.back()
     *   runs instead.
     * - Left-click with no history (history.length === 1): the condition
     *   is false, nothing is prevented, so the anchor's own normal href
     *   (the Prospects list) takes over exactly as a plain link would.
     * - Middle-click / "open in new tab" / no-JS: browsers never run
     *   click-JS for those, so the real <a href> alone decides — again
     *   the Prospects list, a reasonable landing page for a fresh tab
     *   that has no history of its own to go back through anyway.
     *
     * `!== 1` rather than `> 1`: getExtraAttributes() runs every value
     * through Blade's ComponentAttributeBag::merge(), which HTML-escapes
     * strings, and the action's own Blade view then prints that
     * already-escaped value as an attribute, escaping it a SECOND time —
     * `>` becomes `&gt;` then `&amp;gt;`, which the browser only ever
     * decodes once (back to `&gt;`, never valid JS), so Alpine silently
     * failed to evaluate the expression at all and every click fell
     * straight through to the plain fallback href — confirmed directly by
     * inspecting the real rendered attribute in a browser, not assumed.
     * history.length is always >= 1 in a real tab, so `!== 1` is an exact
     * equivalent of `> 1` here and avoids the unsafe character entirely.
     */
    public const BACK_BUTTON_CLICK_HANDLER = <<<'JS'
        if (window.history.length !== 1) {
            $event.preventDefault();
            window.history.back();
        }
        JS;

    /**
     * The fallback destination for ViewProspect's and EditProspect's own
     * "Back" header action — used only when there's no real browser
     * history to go back to (a fresh tab/bookmark) or JavaScript is
     * unavailable. See BACK_BUTTON_CLICK_HANDLER above for why the
     * *primary* Back mechanism is client-side history.back() rather than
     * anything computed here.
     *
     * Multi-hop bug fix: this used to be computed from url()->previous()
     * (Referer header / session's last-tracked GET). That correctly
     * returns to wherever you came from for exactly ONE hop, then breaks:
     * previous() only ever tracks a single last-URL slot, not a real
     * stack, and each Back click is itself a new page load that
     * immediately overwrites that slot with the page just left. Traced
     * through an actual List -> View -> Edit -> Back -> Back sequence to
     * confirm: the second Back read the value the FIRST Back's own page
     * load had just written (the Edit page it came from), not anything
     * further back — producing an infinite View/Edit ping-pong that never
     * reaches the list. A real, arbitrarily-deep history stack is
     * something only the browser itself keeps, which is exactly what
     * history.back() reads.
     */
    public static function getBackFallbackUrl(): string
    {
        return static::getUrl('index');
    }
}
