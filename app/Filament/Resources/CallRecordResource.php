<?php

namespace App\Filament\Resources;

use App\Enums\CallNextAction;
use App\Enums\CallOutcome;
use App\Enums\ProfileSentMode;
use App\Enums\ProfileSentStatus;
use App\Filament\Resources\CallRecordResource\Pages;
use App\Models\CallRecord;
use App\Models\Prospect;
use App\Services\CallRoutingService;
use App\Support\DeletionGuard;
use App\Support\TableBulkActions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;

/**
 * Call Records = the Activity Log (AGENTS.md sections 12, 51). Every call,
 * regardless of outcome, is logged here. Saving a Call Record automatically
 * routes it to Follow-Ups/Appointments/Leads via
 * App\Observers\CallRecordObserver -> App\Services\CallRoutingService.
 */
class CallRecordResource extends Resource
{
    protected static ?string $model = CallRecord::class;

    protected static ?string $navigationIcon = 'heroicon-o-phone';

    protected static ?string $navigationLabel = 'Activity Log';

    protected static ?string $modelLabel = 'Call Record';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 2;

    /**
     * Sentinel Select value for the always-present "+ Create new company…"
     * search-result row (Phase 2 item #4) — a non-numeric string so it can
     * never collide with a real prospects.id.
     */
    private const CREATE_NEW_PROSPECT = '__create_new_prospect__';

    public static function form(Form $form): Form
    {
        return $form->schema(self::formSchema());
    }

    /**
     * Extracted so PipelineBoard's "+ Create company" flow (which needs
     * this exact prospect_id search/select/create-inline field, not a
     * simplified copy) can reuse it directly — matches the
     * ProspectResource::formSchema() precedent.
     *
     * @return array<int, Forms\Components\Component>
     */
    public static function formSchema(): array
    {
        return [
                Forms\Components\Section::make('Call Details')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('prospect_id')
                            ->label('Company')
                            ->required()
                            // Deliberately NOT ->relationship() and NOT
                            // ->createOptionForm(): createOptionForm's
                            // internals crash without relationship()
                            // (they unconditionally walk
                            // getRelationshipName()), but WITH
                            // relationship() restored, relationship-mode
                            // Select silently rejects a selected value
                            // that has no matching Prospect row before it
                            // ever reaches PHP. registerActions() (see
                            // below) sidesteps both — it's a generic
                            // field-adjacent action with no relationship
                            // coupling at all.
                            ->searchable()
                            ->preload()
                            ->live()
                            ->getSearchResultsUsing(fn (string $search) => [self::CREATE_NEW_PROSPECT => '+ Create new company…']
                                + Prospect::query()
                                    ->visibleTo(auth()->user())
                                    ->where('company_name', 'like', "%{$search}%")
                                    ->limit(50)
                                    ->pluck('company_name', 'id')
                                    ->all())
                            ->getOptionLabelUsing(fn ($value) => $value === self::CREATE_NEW_PROSPECT
                                ? '+ Create new company…'
                                : Prospect::find($value)?->company_name)
                            // Pure state normalization only — the sentinel
                            // must never persist as a real prospect_id,
                            // including when state is set programmatically
                            // (e.g. fillForm() in tests, or if JS is
                            // disabled). This does NOT attempt to open the
                            // modal; that trigger lives in
                            // extraAlpineAttributes() below.
                            ->afterStateUpdated(fn ($state, Set $set) => $state === self::CREATE_NEW_PROSPECT
                                && $set('prospect_id', null))
                            // mountFormComponentAction()'s first argument is
                            // this field's absolute statePath, which is NOT
                            // a constant "data.prospect_id" — that's only
                            // true when this schema renders on a Resource's
                            // CreateRecord/EditRecord page (both pages'
                            // getFormStatePath() return 'data'). Reused
                            // as-is inside a page-level Actions\Action (see
                            // PipelineBoard's "+ Log a call" header action,
                            // which calls this same formSchema()), the real
                            // path is "mountedActionsData.{index}.
                            // prospect_id" instead — confirmed by
                            // instrumenting both contexts and inspecting
                            // getFlatComponentsByKey(). $component (bound to
                            // $this via Filament's evaluationIdentifier —
                            // see Forms\Components\Component) exposes
                            // ->getStatePath() so this resolves correctly in
                            // either context rather than hardcoding one of
                            // them. Triggering from genuine client-side
                            // Alpine JS (rather than from inside
                            // afterStateUpdated() above) keeps this a real
                            // top-level $wire call, matching how Filament's
                            // own action buttons trigger it.
                            ->extraAlpineAttributes(fn (Forms\Components\Field $component): array => [
                                'x-init' => sprintf(
                                    <<<'JS'
                                        $watch('state', (value) => {
                                            if (value !== '%s') {
                                                return;
                                            }

                                            state = null;
                                            $wire.mountFormComponentAction('%s', 'createProspect');
                                        })
                                        JS,
                                    self::CREATE_NEW_PROSPECT,
                                    $component->getStatePath(),
                                ),
                            ])
                            // registerActions() (NOT ->suffixAction()) is
                            // the actual root-cause fix: an Action's
                            // isDisabled() is `$this->evaluate($this->
                            // isDisabled) || $this->isHidden()` (see
                            // filament/actions'
                            // Concerns\CanBeDisabled::isDisabled()) — so a
                            // ->suffixAction()->hidden() action is also
                            // implicitly *disabled*, and
                            // mountFormComponentAction() silently refuses
                            // to mount any disabled action (no exception,
                            // no dispatch — exactly the "nothing happens"
                            // symptom seen throughout this investigation).
                            // registerActions() adds the action to the
                            // field's mountable action pool without ever
                            // wiring it into the rendered
                            // prefix/suffix-icon list at all, so it's
                            // never hidden(), never disabled, and never
                            // rendered as a button — the dropdown row is
                            // genuinely the only way to trigger it.
                            // Without this, the mounted action's form model
                            // falls back to this field's *container* model
                            // (CallRecord — this is CallRecordResource's
                            // own create form), since prospect_id has no
                            // ->relationship() of its own. The reused
                            // ProspectResource::formSchema() has an inner
                            // assigned_to field with
                            // ->relationship('assignedEmployee', 'name'),
                            // which crashes when resolved against a
                            // CallRecord instance (no such relation exists,
                            // so Select's internal
                            // RelationshipJoiner::prepareQueryForNoConstraints()
                            // gets passed null where it requires a real
                            // Relation). Declaring the action form's model
                            // explicitly avoids needing prospect_id itself
                            // to carry a ->relationship() — which was tried
                            // earlier and broke selecting the sentinel
                            // value via click.
                            ->actionFormModel(Prospect::class)
                            ->registerActions([
                                Forms\Components\Actions\Action::make('createProspect')
                                    ->modalHeading('Add Company to Database')
                                    ->form(ProspectResource::formSchema())
                                    ->action(function (array $data, Set $set) {
                                        $data['created_by'] = auth()->id();
                                        $prospect = Prospect::create($data);

                                        $set('prospect_id', $prospect->getKey());
                                    }),
                            ]),
                        Forms\Components\DateTimePicker::make('called_at')
                            ->required()
                            ->default(now())
                            ->seconds(false),
                        Forms\Components\Select::make('outcome')
                            ->options(CallOutcome::class)
                            ->required()
                            ->live()
                            ->helperText('Determines what happens next — see the Follow-Ups, Appointments, and Leads panels.'),
                        Forms\Components\TextInput::make('contact_person_spoken_to')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('designation')
                            ->label('Designation')
                            ->placeholder('e.g. Manager, Owner, Procurement Head')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('phone_called')
                            ->tel()
                            ->maxLength(20),
                        // Visibility is driven by the outcome's own routing
                        // rules (CallOutcome::routesToFollowUp()/
                        // routesToAppointment()) rather than a manual
                        // toggle, so the field can never fall out of sync
                        // with what the outcome actually does. Required
                        // whenever visible — the caller must know the
                        // follow-up/appointment time when logging the call
                        // — and read straight into the auto-created
                        // Follow-Up/Appointment record by
                        // CallRoutingService::createFollowUp()/
                        // createAppointment(). Only ever one visible at a
                        // time in practice, since no outcome routes to both.
                        // The model-level "exempt auto-routed insert"
                        // guards on FollowUp/Appointment stay in place —
                        // they cover every OTHER write path (tests,
                        // seeders, future imports/backfills), not this
                        // form, which now never submits a blank value.
                        // Phase 3: visible whenever the outcome could
                        // possibly create a Follow-Up — either
                        // unconditionally (Callback Requested) or as the
                        // caller's explicit, intentional decision
                        // (Concerned Person Not Available / Profile
                        // Requested's optional callback, or Other's
                        // CreateFollowUp next action). Required only where
                        // the Follow-Up is mandatory, not merely possible —
                        // see self::followUpAtRequired().
                        Forms\Components\DateTimePicker::make('follow_up_at')
                            ->label('Follow Up At')
                            ->seconds(false)
                            ->visible(fn (Get $get) => self::followUpAtVisible($get('outcome'), $get('next_action')))
                            ->required(fn (Get $get) => self::followUpAtRequired($get('outcome'), $get('next_action'))),
                        Forms\Components\DateTimePicker::make('appointment_at')
                            ->label('Appointment At')
                            ->seconds(false)
                            ->visible(fn (Get $get) => self::appointmentAtVisible($get('outcome'), $get('next_action')))
                            ->required(fn (Get $get) => self::appointmentAtVisible($get('outcome'), $get('next_action'))),
                        // Phase 3: the explicit, constrained next-action
                        // decision — meaningful ONLY for outcome Other (see
                        // App\Enums\CallNextAction).
                        Forms\Components\Select::make('next_action')
                            ->label('Next Action')
                            ->options(CallNextAction::class)
                            ->live()
                            ->visible(fn (Get $get) => self::resolveOutcome($get('outcome')) === CallOutcome::Others)
                            ->required(fn (Get $get) => self::resolveOutcome($get('outcome')) === CallOutcome::Others),
                    ]),
                // Phase 3: structured Profile Sent tracking — visible only
                // for outcome Profile Requested. Follow-Up itself stays
                // optional/intentional only (see follow_up_at above).
                Forms\Components\Section::make('Profile Sent')
                    ->columns(2)
                    ->visible(fn (Get $get) => self::resolveOutcome($get('outcome')) === CallOutcome::ProfileRequested)
                    ->schema([
                        Forms\Components\Select::make('profile_sent_status')
                            ->options(ProfileSentStatus::class)
                            ->live()
                            ->required(fn (Get $get) => self::resolveOutcome($get('outcome')) === CallOutcome::ProfileRequested),
                        Forms\Components\Select::make('profile_sent_mode')
                            ->label('Mode')
                            ->options(ProfileSentMode::class)
                            ->live()
                            ->required(fn (Get $get) => self::resolveProfileSentStatus($get('profile_sent_status')) === ProfileSentStatus::Sent),
                        Forms\Components\DateTimePicker::make('profile_sent_at')
                            ->label('Sent At')
                            ->seconds(false)
                            ->required(fn (Get $get) => self::resolveProfileSentStatus($get('profile_sent_status')) === ProfileSentStatus::Sent),
                        Forms\Components\Textarea::make('profile_sent_notes')
                            ->label('Notes')
                            ->rows(2)
                            ->columnSpanFull()
                            ->required(fn (Get $get) => self::resolveProfileSentMode($get('profile_sent_mode')) === ProfileSentMode::Other)
                            ->helperText('Required when Mode is Other, to explain how the profile was sent.'),
                    ]),
                // Phase 2 item #5: once a company is selected, show its
                // already-saved Database details inline so the caller
                // doesn't have to leave this screen to look them up.
                Forms\Components\Section::make('Company Details')
                    ->schema([
                        Forms\Components\Placeholder::make('prospect_details')
                            ->label('')
                            ->content(fn (Get $get) => new HtmlString(
                                view('filament.forms.prospect-call-details', [
                                    'prospect' => Prospect::find($get('prospect_id')),
                                ])->render()
                            ))
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (Get $get) => filled($get('prospect_id')) && $get('prospect_id') !== self::CREATE_NEW_PROSPECT),
                Forms\Components\Section::make('Notes')
                    ->schema([
                        // Required for any outcome where a real conversation
                        // actually happened (CallOutcome::requiresNotes() —
                        // every outcome except the three "never connected"
                        // ones), so there's something on record. Was
                        // previously scoped to just Others (the catch-all
                        // with no defined next action). Mirrors the same
                        // required()+rule() pairing LeadResource uses for
                        // "Notes required when Validated" — plain required()
                        // alone would accept a whitespace-only value.
                        Forms\Components\Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull()
                            ->required(fn (Get $get) => self::outcomeRequiresNotes($get('outcome')))
                            ->validationMessages([
                                'required' => 'Notes are required for this outcome — only No Answer, Switched Off, and Not Reachable are exempt.',
                            ])
                            ->rule(
                                fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                                    if (self::outcomeRequiresNotes($get('outcome')) && blank($value)) {
                                        $fail('Notes are required for this outcome — only No Answer, Switched Off, and Not Reachable are exempt.');
                                    }
                                },
                            ),
                    ]),
        ];
    }

    /**
     * $get() may hand back either the raw string value or the hydrated
     * CallOutcome case depending on how the form state got there — see the
     * comment above the `notes` field (mirrors LeadResource::
     * stageIsValidated()).
     */
    private static function outcomeRequiresNotes(mixed $outcome): bool
    {
        return self::resolveOutcome($outcome)?->requiresNotes() ?? false;
    }

    private static function followUpAtVisible(mixed $outcome, mixed $nextAction): bool
    {
        $resolved = self::resolveOutcome($outcome);

        if ($resolved === null) {
            return false;
        }

        if ($resolved->routesToFollowUp() || $resolved->routesToConditionalFollowUp()) {
            return true;
        }

        return $resolved === CallOutcome::Others && self::resolveNextAction($nextAction) === CallNextAction::CreateFollowUp;
    }

    /** Required only where the Follow-Up is mandatory, not merely possible (Concerned Person Not Available / Profile Requested's callback stays optional). */
    private static function followUpAtRequired(mixed $outcome, mixed $nextAction): bool
    {
        $resolved = self::resolveOutcome($outcome);

        if ($resolved === null) {
            return false;
        }

        if ($resolved->routesToFollowUp()) {
            return true;
        }

        return $resolved === CallOutcome::Others && self::resolveNextAction($nextAction) === CallNextAction::CreateFollowUp;
    }

    private static function appointmentAtVisible(mixed $outcome, mixed $nextAction): bool
    {
        $resolved = self::resolveOutcome($outcome);

        if ($resolved === null) {
            return false;
        }

        if ($resolved->routesToAppointment()) {
            return true;
        }

        return $resolved === CallOutcome::Others && self::resolveNextAction($nextAction) === CallNextAction::CreateAppointment;
    }

    private static function resolveOutcome(mixed $outcome): ?CallOutcome
    {
        return $outcome instanceof CallOutcome ? $outcome : CallOutcome::tryFrom((string) $outcome);
    }

    private static function resolveNextAction(mixed $nextAction): ?CallNextAction
    {
        return $nextAction instanceof CallNextAction ? $nextAction : CallNextAction::tryFrom((string) $nextAction);
    }

    private static function resolveProfileSentStatus(mixed $status): ?ProfileSentStatus
    {
        return $status instanceof ProfileSentStatus ? $status : ProfileSentStatus::tryFrom((string) $status);
    }

    private static function resolveProfileSentMode(mixed $mode): ?ProfileSentMode
    {
        return $mode instanceof ProfileSentMode ? $mode : ProfileSentMode::tryFrom((string) $mode);
    }

    /**
     * Extracted from table() so the Prospect View page's Call Records
     * mini-table (see App\Filament\Widgets\ProspectCallRecordsTable) can
     * reuse the exact same column set rather than duplicating it —
     * matches the ProspectResource::formSchema() precedent for form
     * fields.
     *
     * @return array<int, Tables\Columns\Column>
     */
    public static function columns(): array
    {
        return [
            Tables\Columns\TextColumn::make('called_at')
                ->dateTime('d M Y, h:i A')
                ->sortable(),
            Tables\Columns\TextColumn::make('prospect.company_name')
                ->label('Company')
                ->searchable()
                ->sortable(),
            Tables\Columns\TextColumn::make('outcome')
                ->badge()
                ->sortable(),
            Tables\Columns\TextColumn::make('caller.name')
                ->label('Called By')
                ->badge()
                ->sortable()
                // Employees only ever see their own calls (see
                // CallRecord::scopeVisibleTo()), so this column always
                // just shows their own name for them — redundant, not
                // a toggleable default like the others, just hidden
                // outright for non-admins.
                ->visible(fn () => auth()->user()->isAdmin()),
            Tables\Columns\TextColumn::make('follow_up_at')
                ->dateTime('d M Y, h:i A')
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('appointment_at')
                ->dateTime('d M Y, h:i A')
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('notes')
                ->limit(40)
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(static::columns())
            ->filters([
                Tables\Filters\SelectFilter::make('outcome')
                    ->options(CallOutcome::class),
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Called By')
                    ->relationship('caller', 'name')
                    ->visible(fn () => auth()->user()->isAdmin()),
                Tables\Filters\Filter::make('called_at')
                    ->form([
                        Forms\Components\DatePicker::make('from'),
                        Forms\Components\DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('called_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('called_at', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    self::correctOutcomeAction(),
                    Tables\Actions\DeleteAction::make()
                        ->visible(fn () => auth()->user()->isAdmin())
                        ->before(fn (CallRecord $record) => DeletionGuard::guardRecord($record, 'call record')),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    TableBulkActions::deselectAll(),
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()->isAdmin())
                        ->before(fn (Collection $records) => DeletionGuard::guardRecords(
                            $records,
                            'call records',
                            fn (CallRecord $call) => $call->called_at->format('d M Y').' — '.$call->prospect->company_name,
                        )),
                ]),
            ])
            ->defaultSort('called_at', 'desc')
            ->emptyStateHeading('No calls logged yet.')
            ->emptyStateDescription('Every call you make against a prospect shows up here — successful or not.')
            ->emptyStateIcon('heroicon-o-phone');
    }

    /**
     * Phase 3: the explicit, intentional "Correct Outcome" action —
     * deliberately NOT the generic Edit action, which never reconciles
     * routing. Shows the existing outcome, captures the corrected outcome
     * plus a mandatory correction reason, collects whatever destination-
     * specific data the corrected outcome requires (reusing the exact same
     * conditional fields/visibility as logging a call), and delegates to
     * CallRoutingService::correctOutcome() — which enforces the safety
     * boundary (existing downstream history blocks the correction) and
     * executes routing exactly once. Authorization mirrors ordinary Call
     * edit (auth()->user()->can('update', $record)) — no stricter tier,
     * consistent with how Appointment/Demo outcome recording is gated.
     */
    private static function correctOutcomeAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('correctOutcome')
            ->label('Correct Outcome')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn (CallRecord $record) => auth()->user()->can('update', $record))
            ->form(fn (CallRecord $record) => [
                Forms\Components\Placeholder::make('current_outcome')
                    ->label('Current Outcome')
                    ->content(fn () => $record->outcome->getLabel()),
                Forms\Components\Select::make('outcome')
                    ->label('Corrected Outcome')
                    ->options(CallOutcome::class)
                    ->required()
                    ->live(),
                Forms\Components\Textarea::make('correction_reason')
                    ->label('Correction Reason')
                    ->required()
                    ->rows(2)
                    ->helperText('Why is the previously recorded outcome being changed?'),
                Forms\Components\DateTimePicker::make('follow_up_at')
                    ->label('Follow Up At')
                    ->seconds(false)
                    ->visible(fn (Forms\Get $get) => self::followUpAtVisible($get('outcome'), $get('next_action')))
                    ->required(fn (Forms\Get $get) => self::followUpAtRequired($get('outcome'), $get('next_action'))),
                Forms\Components\DateTimePicker::make('appointment_at')
                    ->label('Appointment At')
                    ->seconds(false)
                    ->visible(fn (Forms\Get $get) => self::appointmentAtVisible($get('outcome'), $get('next_action')))
                    ->required(fn (Forms\Get $get) => self::appointmentAtVisible($get('outcome'), $get('next_action'))),
                Forms\Components\Select::make('next_action')
                    ->label('Next Action')
                    ->options(CallNextAction::class)
                    ->live()
                    ->visible(fn (Forms\Get $get) => self::resolveOutcome($get('outcome')) === CallOutcome::Others)
                    ->required(fn (Forms\Get $get) => self::resolveOutcome($get('outcome')) === CallOutcome::Others),
                Forms\Components\Select::make('profile_sent_status')
                    ->label('Profile Sent Status')
                    ->options(ProfileSentStatus::class)
                    ->live()
                    ->visible(fn (Forms\Get $get) => self::resolveOutcome($get('outcome')) === CallOutcome::ProfileRequested)
                    ->required(fn (Forms\Get $get) => self::resolveOutcome($get('outcome')) === CallOutcome::ProfileRequested),
                Forms\Components\Select::make('profile_sent_mode')
                    ->label('Profile Sent Mode')
                    ->options(ProfileSentMode::class)
                    ->live()
                    ->visible(fn (Forms\Get $get) => self::resolveOutcome($get('outcome')) === CallOutcome::ProfileRequested)
                    ->required(fn (Forms\Get $get) => self::resolveProfileSentStatus($get('profile_sent_status')) === ProfileSentStatus::Sent),
                Forms\Components\DateTimePicker::make('profile_sent_at')
                    ->label('Profile Sent At')
                    ->seconds(false)
                    ->visible(fn (Forms\Get $get) => self::resolveOutcome($get('outcome')) === CallOutcome::ProfileRequested)
                    ->required(fn (Forms\Get $get) => self::resolveProfileSentStatus($get('profile_sent_status')) === ProfileSentStatus::Sent),
                Forms\Components\Textarea::make('profile_sent_notes')
                    ->label('Profile Sent Notes')
                    ->rows(2)
                    ->visible(fn (Forms\Get $get) => self::resolveOutcome($get('outcome')) === CallOutcome::ProfileRequested)
                    ->required(fn (Forms\Get $get) => self::resolveProfileSentMode($get('profile_sent_mode')) === ProfileSentMode::Other),
                Forms\Components\Textarea::make('notes')
                    ->label('Notes')
                    ->rows(2)
                    ->helperText('Optional — updates the Call\'s own Notes alongside the correction.'),
            ])
            ->action(function (CallRecord $record, array $data) {
                try {
                    app(CallRoutingService::class)->correctOutcome(
                        $record,
                        CallOutcome::from($data['outcome']),
                        $data['correction_reason'],
                        array_filter([
                            'follow_up_at' => $data['follow_up_at'] ?? null,
                            'appointment_at' => $data['appointment_at'] ?? null,
                            'next_action' => filled($data['next_action'] ?? null) ? CallNextAction::from($data['next_action']) : null,
                            'profile_sent_status' => filled($data['profile_sent_status'] ?? null) ? ProfileSentStatus::from($data['profile_sent_status']) : null,
                            'profile_sent_mode' => filled($data['profile_sent_mode'] ?? null) ? ProfileSentMode::from($data['profile_sent_mode']) : null,
                            'profile_sent_at' => $data['profile_sent_at'] ?? null,
                            'profile_sent_notes' => $data['profile_sent_notes'] ?? null,
                            'notes' => $data['notes'] ?? null,
                        ], fn ($value) => $value !== null),
                    );
                } catch (\LogicException $e) {
                    \Filament\Notifications\Notification::make()
                        ->title("Couldn't correct this Call's outcome")
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                    throw new \Filament\Support\Exceptions\Halt;
                }

                \Filament\Notifications\Notification::make()->title('Outcome corrected')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCallRecords::route('/'),
            'create' => Pages\CreateCallRecord::route('/create'),
            'view' => Pages\ViewCallRecord::route('/{record}'),
            'edit' => Pages\EditCallRecord::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }
}
