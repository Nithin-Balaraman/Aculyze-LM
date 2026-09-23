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
            // Root cause of the "search returns unrelated-looking rows"
            // report: 6 of the 8 ->searchable() columns below (contact
            // person, email, industry, city, address, locality) are
            // ->toggleable(isToggledHiddenByDefault: true) — hidden from
            // the table unless the user opts in. A LIKE '%term%' match
            // against one of those hidden columns is completely correct
            // (confirmed directly against the dev DB: e.g. searching "x"
            // legitimately matches the repeated industry value
            // "Textiles"), but with only Company/Telephone visible by
            // default, the matching text is nowhere on screen, so the
            // result looks unrelated to the search term. This description
            // is the fix: it's a real, always-visible line (independent of
            // ->searchPlaceholder(), which disappears once typing starts,
            // i.e. exactly when a user is confused by results on screen),
            // not a change to which columns are searched or how.
            ->description('Also searches Contact Person, Email, Industry, City, Address and Locality — even when those columns are hidden. Use the column-toggle button to reveal them.')
            ->columns([
                Tables\Columns\TextColumn::make('company_name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('contact_person')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('telephone')
                    ->label('Telephone')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('industry')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('city')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('address')
                    ->searchable()
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('locality')
                    ->searchable()
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
            ->filters([
                Tables\Filters\SelectFilter::make('assigned_to')
                    ->label('Assigned Employee')
                    ->relationship('assignedEmployee', 'name')
                    ->visible(fn () => auth()->user()->isAdmin()),
                Tables\Filters\SelectFilter::make('industry')
                    ->options(fn () => Prospect::query()->whereNotNull('industry')->distinct()->pluck('industry', 'industry')),
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
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No prospects yet.')
            ->emptyStateDescription('Add a company to start the pipeline — every call starts here.')
            ->emptyStateIcon('heroicon-o-building-office-2');
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
