<?php

namespace App\Filament\Resources;

use App\Enums\CallOutcome;
use App\Enums\ContactMode;
use App\Enums\FollowUpStatus;
use App\Filament\Resources\FollowUpResource\Pages;
use App\Models\CallRecord;
use App\Models\FollowUp;
use App\Support\DeletionGuard;
use App\Support\TableBulkActions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * Follow-Ups panel (AGENTS.md section 17). Mostly populated automatically
 * by App\Services\CallRoutingService from No Answer / Switched Off / Not
 * Reachable / Callback Requested calls, but can also be created directly.
 *
 * Two distinct resolutions, per the Change Request "Decisions 3 & 4":
 * - Completed: the retry call finally reached the prospect. Logs a real
 *   new Call Record and routes it through the exact same
 *   App\Services\CallRoutingService every other call uses — no separate
 *   routing path.
 * - Close: giving up on this one. Just archives it (no new activity).
 * Regular users no longer see Delete here at all (Admin still does).
 */
class FollowUpResource extends Resource
{
    protected static ?string $model = FollowUp::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationLabel = 'Follow-Ups';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 3;

    /**
     * Shared with ListFollowUps::updatedActiveTab() so the two stay in
     * sync — one place defining which column History/Lost group by.
     */
    public const GROUP_BY_COMPANY = 'prospect.company_name';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make()
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('prospect_id')
                            ->label('Company')
                            ->relationship(
                                'prospect',
                                'company_name',
                                modifyQueryUsing: fn (Builder $query) => $query->visibleTo(auth()->user()),
                            )
                            ->required()
                            ->searchable()
                            ->preload(),
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\DateTimePicker::make('follow_up_at')
                                    ->required()
                                    ->seconds(false),
                                Forms\Components\Select::make('contact_mode')
                                    ->label('Contact Mode')
                                    ->options(ContactMode::class),
                            ]),
                        Forms\Components\TextInput::make('reason')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make('status')
                            ->options(FollowUpStatus::class)
                            ->required()
                            ->default(FollowUpStatus::Pending)
                            ->live(),
                        // Neither of these persists to the FollowUp record
                        // itself — EditFollowUp::handleRecordUpdate() /
                        // CreateFollowUp::handleRecordCreation() pull them
                        // back out of the form data and use them to create a
                        // new Call Record, exactly like the row-action
                        // "Completed" modal's own fields (FollowUpResource::
                        // table()) do. Required only when Status is being set
                        // to Completed here — matching the modal's
                        // enforcement — so this is both the reactive UI
                        // validation and, since Livewire validates
                        // server-side regardless of JS, the actual guard
                        // against submitting Completed with no outcome
                        // captured.
                        Forms\Components\Select::make('outcome')
                            ->label('Call Outcome')
                            ->options(CallOutcome::class)
                            ->live()
                            ->visible(fn (Forms\Get $get) => self::statusIsCompleting($get('status')))
                            ->required(fn (Forms\Get $get) => self::statusIsCompleting($get('status')))
                            ->helperText('You reached them — log what happened on this call, same as the row-action Completed modal.'),
                        // Mirrors CallRecordResource::form()'s identical
                        // appointment_at/follow_up_at pair exactly — same
                        // routing rules (CallOutcome::routesToAppointment()/
                        // routesToFollowUp()), same visible-whenever-required
                        // pairing. "Next Follow-Up At" (not "Follow up at",
                        // already taken above by *this* Follow-Up's own
                        // field) since an outcome like No Answer here creates
                        // a brand new Follow-Up, distinct from the one being
                        // completed.
                        Forms\Components\DateTimePicker::make('appointment_at')
                            ->label('Appointment At')
                            ->seconds(false)
                            ->visible(fn (Forms\Get $get) => self::statusIsCompleting($get('status')) && self::outcomeRoutesToAppointment($get('outcome')))
                            ->required(fn (Forms\Get $get) => self::statusIsCompleting($get('status')) && self::outcomeRoutesToAppointment($get('outcome'))),
                        Forms\Components\DateTimePicker::make('new_follow_up_at')
                            ->label('Next Follow-Up At')
                            ->seconds(false)
                            ->visible(fn (Forms\Get $get) => self::statusIsCompleting($get('status')) && self::outcomeRoutesToFollowUp($get('outcome')))
                            ->required(fn (Forms\Get $get) => self::statusIsCompleting($get('status')) && self::outcomeRoutesToFollowUp($get('outcome'))),
                        Forms\Components\Textarea::make('call_notes')
                            ->label('Call Notes')
                            ->rows(3)
                            ->visible(fn (Forms\Get $get) => self::statusIsCompleting($get('status')))
                            ->required(fn (Forms\Get $get) => self::statusIsCompleting($get('status'))),
                        Forms\Components\Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * $get()/form data may hand back either the raw string value or the
     * hydrated enum case depending on how the form state got there (a
     * record-hydrated Edit form's initial value is already a plain string —
     * Eloquent's attributesToArray() normalizes backed enums on the way out
     * — but a *live* Select-with-enum-options interaction, e.g. picking a
     * new option in the browser, stores the actual enum instance instead;
     * confirmed live — a naive `$get('status') === FollowUpStatus::
     * Completed->value` string comparison silently never matched on the
     * Create form, whose Status field starts from ->default() rather than a
     * hydrated record, once the user actually changed it). Mirrors
     * CallRecordResource::resolveOutcome() / LeadResource::
     * stageIsValidated() exactly, the same fix already established there.
     */
    public static function resolveStatus(mixed $status): ?FollowUpStatus
    {
        return $status instanceof FollowUpStatus ? $status : FollowUpStatus::tryFrom((string) $status);
    }

    private static function resolveOutcome(mixed $outcome): ?CallOutcome
    {
        return $outcome instanceof CallOutcome ? $outcome : CallOutcome::tryFrom((string) $outcome);
    }

    public static function statusIsCompleting(mixed $status): bool
    {
        return self::resolveStatus($status) === FollowUpStatus::Completed;
    }

    private static function outcomeRoutesToAppointment(mixed $outcome): bool
    {
        return self::resolveOutcome($outcome)?->routesToAppointment() ?? false;
    }

    private static function outcomeRoutesToFollowUp(mixed $outcome): bool
    {
        return self::resolveOutcome($outcome)?->routesToFollowUp() ?? false;
    }

    /**
     * Extracted from table() so the Prospect View page's Follow-Ups
     * mini-table (see App\Filament\Widgets\ProspectFollowUpsTable) can
     * reuse the exact same column set rather than duplicating it —
     * matches the ProspectResource::formSchema() precedent for form
     * fields.
     *
     * @return array<int, Tables\Columns\Column>
     */
    public static function columns(): array
    {
        return [
            Tables\Columns\TextColumn::make('prospect.company_name')
                ->label('Company')
                ->searchable()
                ->sortable(),
            Tables\Columns\TextColumn::make('reason')
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('follow_up_at')
                ->dateTime('d M Y, h:i A')
                ->sortable()
                ->placeholder('Not scheduled'),
            Tables\Columns\TextColumn::make('status')
                ->badge()
                ->sortable(),
            Tables\Columns\TextColumn::make('responsibleEmployee.name')
                ->label('Employee')
                ->badge()
                ->sortable(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(static::columns())
            ->filters([
                // Pending vs. History is now owned by the page's tabs (UX
                // Fixes Batch Issue 3) rather than this filter, so there is
                // only ever one place that decides what "pending" means.
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Employee')
                    ->relationship('responsibleEmployee', 'name')
                    ->visible(fn () => auth()->user()->isAdmin()),
            ])
            // Phase 2 item #3: History and Lost are look-back/audit views —
            // grouping by company collapses noise (one row's worth of
            // follow-ups against a company that's been called 20 times)
            // without hiding anything, since the underlying records are
            // still there, just collapsed until clicked. Pending stays flat
            // — it's an actionable queue, not something to browse by
            // company. Only one grouping dimension is offered, so
            // Filament's built-in "Groups" picker is hidden rather than
            // left visible with nothing else to switch to.
            ->groups([
                Group::make(self::GROUP_BY_COMPANY)
                    ->titlePrefixedWithLabel(false)
                    ->collapsible()
                    ->getDescriptionFromRecordUsing(fn (FollowUp $record, $livewire) => static::groupSummary($record, $livewire)),
            ])
            ->defaultGroup(fn ($livewire) => in_array($livewire->activeTab ?? 'pending', ['history', 'lost'], true)
                ? self::GROUP_BY_COMPANY
                : null)
            ->groupingSettingsHidden()
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    // Carries the tab the user is currently on through to
                    // the Edit page as ?activeTab=..., so getRedirectUrl()
                    // on EditFollowUp can send them back to the same tab
                    // (History/Lost) instead of always Pending — see that
                    // page for the other half of this.
                    Tables\Actions\EditAction::make()
                        ->url(fn (FollowUp $record, $livewire): string => static::getUrl('edit', [
                            'record' => $record,
                            'activeTab' => $livewire->activeTab,
                        ])),
                    Tables\Actions\Action::make('completed')
                        ->label('Completed')
                        ->icon('heroicon-o-phone')
                        ->color('success')
                        ->visible(fn (FollowUp $record) => $record->status === FollowUpStatus::Pending && auth()->user()->can('update', $record))
                        ->form([
                            Forms\Components\Select::make('outcome')
                                ->label('Call Outcome')
                                ->options(CallOutcome::class)
                                ->required()
                                ->live()
                                ->helperText('You reached them — log what happened on this call, same as logging any other call.'),
                            // Mirrors CallRecordResource::form()'s identical
                            // pair — an outcome here can route to an
                            // Appointment and/or a new Follow-Up exactly like
                            // any other logged call (App\Services\
                            // CallRoutingService doesn't treat this call any
                            // differently), so the same date/time it needs
                            // has to be collected here too, not left blank.
                            Forms\Components\DateTimePicker::make('appointment_at')
                                ->label('Appointment At')
                                ->seconds(false)
                                ->visible(fn (Forms\Get $get) => static::outcomeRoutesToAppointment($get('outcome')))
                                ->required(fn (Forms\Get $get) => static::outcomeRoutesToAppointment($get('outcome'))),
                            Forms\Components\DateTimePicker::make('new_follow_up_at')
                                ->label('Follow Up At')
                                ->seconds(false)
                                ->visible(fn (Forms\Get $get) => static::outcomeRoutesToFollowUp($get('outcome')))
                                ->required(fn (Forms\Get $get) => static::outcomeRoutesToFollowUp($get('outcome'))),
                            Forms\Components\Textarea::make('notes')
                                ->label('Notes')
                                ->required()
                                ->rows(3),
                        ])
                        ->action(function (FollowUp $record, array $data) {
                            DB::transaction(function () use ($record, $data) {
                                // A real new Call Record, routed by the exact
                                // same CallRoutingService every other call
                                // uses (via CallRecordObserver on `created`) —
                                // not a parallel/duplicate routing path.
                                CallRecord::create([
                                    'prospect_id' => $record->prospect_id,
                                    'user_id' => auth()->id(),
                                    'called_at' => now(),
                                    'outcome' => $data['outcome'],
                                    'notes' => $data['notes'],
                                    'appointment_at' => $data['appointment_at'] ?? null,
                                    'follow_up_at' => $data['new_follow_up_at'] ?? null,
                                    // Marks this Call Record as existing
                                    // purely to drive CallRoutingService —
                                    // see CallRecord::scopeDirectlyLogged().
                                    'follow_up_id' => $record->id,
                                ]);

                                $record->update(['status' => FollowUpStatus::Completed]);
                            });
                        }),
                    Tables\Actions\Action::make('close')
                        ->label('Close')
                        ->icon('heroicon-o-archive-box-x-mark')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalDescription('This follow-up will be archived and removed from your default list. It is not deleted — history is retained.')
                        ->visible(fn (FollowUp $record) => $record->status === FollowUpStatus::Pending && auth()->user()->can('update', $record))
                        ->form([
                            Forms\Components\Textarea::make('notes')
                                ->label('Notes')
                                ->required()
                                ->rows(3)
                                ->helperText('Required — why this follow-up is being closed.'),
                        ])
                        ->action(fn (FollowUp $record, array $data) => $record->update([
                            'status' => FollowUpStatus::Cancelled,
                            'notes' => $data['notes'],
                        ])),
                    Tables\Actions\DeleteAction::make()
                        ->visible(fn () => auth()->user()->isAdmin())
                        ->before(function (FollowUp $record) {
                            $record->deleteHarmlessGeneratedCallRecord();
                            DeletionGuard::guardRecord($record, 'follow-up');
                        }),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    TableBulkActions::deselectAll(),
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()->isAdmin())
                        ->before(fn (Collection $records) => DeletionGuard::guardRecords(
                            $records,
                            'follow-ups',
                            fn (FollowUp $followUp) => $followUp->prospect->company_name,
                        )),
                ]),
            ])
            ->defaultSort('follow_up_at', 'asc')
            ->emptyStateHeading(fn ($livewire) => match ($livewire->activeTab ?? 'pending') {
                'history' => 'No follow-up history available.',
                'lost' => 'No lost follow-ups.',
                default => 'No pending follow-ups.',
            })
            ->emptyStateDescription(fn ($livewire) => match ($livewire->activeTab ?? 'pending') {
                'history' => 'Completed and closed follow-ups will show up here.',
                'lost' => 'Follow-ups closed without a positive outcome will show up here.',
                default => 'Follow-ups land here automatically when a call needs one.',
            })
            ->emptyStateIcon('heroicon-o-arrow-path');
    }

    /**
     * The group header's subtitle: how many follow-ups against this
     * company are in the *currently active tab's* filtered set (History =
     * Completed + Cancelled, Lost = Cancelled only) and when the most
     * recent one was — mirrors ListFollowUps::getTabs()'s own status
     * filtering rather than a raw all-time count, so it matches what's
     * actually on screen.
     *
     * Also carries the "Summary" button. Filament's group header partial
     * (vendor/filament/tables/.../components/group/header.blade.php) has
     * no slot for extra actions, and this app has already deliberately
     * chosen not to eject/override that view once before (see
     * list-follow-ups-footer.blade.php's comment) since it's shared by
     * every grouped table in the app, not just this one. Blade's `{{ }}`
     * calls e(), which passes Htmlable values through unescaped — so
     * returning an HtmlString here (instead of a plain string) lets a real
     * <button wire:click="mountAction(...)"> ride along inside the
     * description `<p>` Filament already renders, with zero vendor files
     * touched. $countLabel/$recentLabel are both derived from a count and
     * a formatted date, never free text, so this is safe without a
     * separate e() pass over them.
     *
     * This calls the page-level mountAction() (see ListFollowUps::
     * summaryAction()), not mountTableAction() — a *table row* action
     * bound ->visible(false) also comes back isDisabled() === true in
     * this Filament version (isDisabled() folds in isHidden()), so a
     * hidden table action can never actually be mounted, only rendered
     * or not. A page-level action sidesteps that entirely: it's simply
     * never placed in any auto-rendering slot (getHeaderActions() etc.),
     * so it needs no ->visible(false) and stays mountable. It carries the
     * target Follow-Up as an argument rather than a bound $record.
     */
    private static function groupSummary(FollowUp $record, $livewire): HtmlString
    {
        $statuses = ($livewire->activeTab ?? 'pending') === 'lost'
            ? [FollowUpStatus::Cancelled]
            : [FollowUpStatus::Completed, FollowUpStatus::Cancelled];

        $matching = FollowUp::query()
            ->visibleTo(auth()->user())
            ->where('prospect_id', $record->prospect_id)
            ->whereIn('status', $statuses);

        $count = $matching->count();
        $mostRecent = $matching->max('follow_up_at');

        $countLabel = $count === 1 ? '1 follow-up' : "{$count} follow-ups";
        $recentLabel = $mostRecent ? Carbon::parse($mostRecent)->format('d M Y') : '—';

        // Single-quoted HTML attribute since the JSON arguments payload
        // itself only ever contains double quotes.
        $summaryButton = sprintf(
            '<button type="button" wire:click=\'mountAction("summary", %s)\' class="follow-up-summary-button ms-2 text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">Summary</button>',
            json_encode(['followUpId' => $record->id]),
        );

        return new HtmlString("{$countLabel} · most recent {$recentLabel}{$summaryButton}");
    }

    /**
     * Every Completed/Cancelled Follow-Up against this record's company,
     * most recent first, for the "Summary" modal (see ListFollowUps::
     * summaryAction(), which is what actually calls this). A Completed
     * Follow-Up's date/notes live on the Call Record its "Completed"
     * action generated (generatedCallRecord — see App\Models\FollowUp); a
     * Cancelled one has no dedicated cancelled_at column, so updated_at
     * (set the moment the "Close" action flips the status) and its own
     * notes column are all there is. Two different date sources per
     * status means "most recent first" has to be resolved in PHP rather
     * than a single ORDER BY.
     *
     * map() downgrades to a plain \Illuminate\Support\Collection here (not
     * Eloquent's) since the mapped items are arrays, not Models.
     *
     * @return \Illuminate\Support\Collection<int, array{status: FollowUpStatus, occurred_at: ?Carbon, notes: ?string}>
     */
    public static function companyFollowUpHistory(FollowUp $record): \Illuminate\Support\Collection
    {
        return FollowUp::query()
            ->visibleTo(auth()->user())
            ->where('prospect_id', $record->prospect_id)
            ->whereIn('status', [FollowUpStatus::Completed, FollowUpStatus::Cancelled])
            ->with('generatedCallRecord')
            ->get()
            ->map(fn (FollowUp $followUp) => [
                'status' => $followUp->status,
                'occurred_at' => $followUp->status === FollowUpStatus::Completed
                    ? $followUp->generatedCallRecord?->called_at
                    : $followUp->updated_at,
                'notes' => $followUp->status === FollowUpStatus::Completed
                    ? $followUp->generatedCallRecord?->notes
                    : $followUp->notes,
            ])
            ->sortByDesc('occurred_at')
            ->values();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFollowUps::route('/'),
            'create' => Pages\CreateFollowUp::route('/create'),
            'view' => Pages\ViewFollowUp::route('/{record}'),
            'edit' => Pages\EditFollowUp::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }
}
