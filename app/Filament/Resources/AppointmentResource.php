<?php

namespace App\Filament\Resources;

use App\Enums\AppointmentOutcome;
use App\Enums\AppointmentStage;
use App\Enums\AppointmentStatus;
use App\Enums\DemoMode;
use App\Filament\Resources\AppointmentResource\Pages;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\User;
use App\Services\RescheduleService;
use App\Services\WorkflowTransitionService;
use App\Support\TableBulkActions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Exceptions\Halt;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Appointment Call Sheet (AGENTS.md sections 18-19, 42). Stage names/order
 * are provisional — see App\Enums\AppointmentStage.
 */
class AppointmentResource extends Resource
{
    protected static ?string $model = Appointment::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Appointments';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form->schema(self::formSchema());
    }

    /**
     * Extracted so PipelineBoard's in-board Edit/View actions (which reuse
     * every resource's real form for full parity — see PipelineBoard's
     * editRecordAction()/viewRecordAction()) can call this directly instead
     * of duplicating these fields — matches the ProspectResource::
     * formSchema() precedent.
     *
     * @return array<int, Forms\Components\Component>
     */
    public static function formSchema(): array
    {
        return [
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
                        Forms\Components\Select::make('assigned_to')
                            ->label('Assigned Employee')
                            ->options(fn () => User::query()->pluck('name', 'id'))
                            ->default(fn () => auth()->id())
                            ->required()
                            ->searchable()
                            ->disabled(fn () => ! auth()->user()->isAdmin())
                            ->dehydrated(),
                        Forms\Components\DateTimePicker::make('appointment_at')
                            ->required()
                            ->seconds(false)
                            // Phase 2: normal Edit must never silently
                            // change an ALREADY-SET schedule — the
                            // dedicated "Reschedule" row action is the
                            // only way (see table()). A still-NULL
                            // schedule (auto-routed from a call before the
                            // exact time was known) stays editable here —
                            // filling it in for the first time is not a
                            // reschedule.
                            ->disabled(fn (?Appointment $record) => $record?->appointment_at !== null)
                            ->dehydrated(fn (?Appointment $record) => $record?->appointment_at === null),
                        // ->live() so outcome_notes' required()/rule()
                        // below react the moment Stage changes — same
                        // mechanism as LeadResource's stage-driven Notes
                        // requirement.
                        // Phase 3 correction round 2: legacy `stage` is
                        // editable only at creation, when it drives the
                        // create-only stage->status fallback (see
                        // Appointment::booted()) — there is no established
                        // normalized workflow state yet to diverge from. On
                        // an EXISTING record it is read-only: normalized
                        // status is authoritative, and every real business
                        // conclusion (Succeeded/Not Succeeded/any other
                        // outcome) must go through the Record Outcome
                        // action, never a hand-edited legacy value here.
                        // Mirrors appointment_at's own disabled-on-existing/
                        // editable-on-create pattern below.
                        Forms\Components\Select::make('stage')
                            ->options(AppointmentStage::class)
                            ->required()
                            ->default(AppointmentStage::AppointmentMade)
                            ->live()
                            ->disabled(fn (?Appointment $record) => $record !== null)
                            ->dehydrated(fn (?Appointment $record) => $record === null)
                            ->helperText(fn (?Appointment $record) => $record !== null
                                ? 'Read-only — use Record Outcome to change this Appointment\'s business state.'
                                : null),
                        Forms\Components\Textarea::make('meeting_notes')
                            ->rows(3)
                            ->columnSpanFull(),
                        // Required the moment Stage reaches a terminal value
                        // (Succeeded or Not Succeeded) — a final result
                        // should always leave a record of what happened.
                        // Mirrors the same required()+rule() pairing
                        // LeadResource uses for "Notes required when
                        // Validated" — plain required() alone would accept a
                        // whitespace-only value.
                        Forms\Components\Textarea::make('outcome_notes')
                            ->rows(3)
                            ->columnSpanFull()
                            ->required(fn (Get $get) => self::stageIsTerminal($get('stage')))
                            ->validationMessages([
                                'required' => 'Outcome Notes are required when the stage is Succeeded or Not Succeeded.',
                            ])
                            ->rule(
                                fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                                    if (self::stageIsTerminal($get('stage')) && blank($value)) {
                                        $fail('Outcome Notes are required when the stage is Succeeded or Not Succeeded.');
                                    }
                                },
                            ),
                    ]),
                // is_lost/lost_reason/lost_at/lost_at_stage are deliberately
                // absent from Appointment's own $fillable (only markLost()
                // sets them, via forceFill() — see its own docblock:
                // "without touching its normal `stage` field or stage
                // history"), so these are read-only Placeholders, never an
                // editable field — there was previously no way to see WHY a
                // Lost Appointment was marked Lost anywhere on this page.
                Forms\Components\Section::make('Lost')
                    ->visible(fn (?Appointment $record) => $record?->is_lost ?? false)
                    ->columns(3)
                    ->schema([
                        Forms\Components\Placeholder::make('lost_reason_display')
                            ->label('Reason')
                            ->columnSpanFull()
                            ->content(fn (Appointment $record) => $record->lost_reason ?: '—'),
                        Forms\Components\Placeholder::make('lost_at_stage_display')
                            ->label('Stage At Time Of Loss')
                            ->content(fn (Appointment $record) => $record->lost_at_stage?->getLabel() ?? '—'),
                        Forms\Components\Placeholder::make('lost_at_display')
                            ->label('Lost At')
                            ->content(fn (Appointment $record) => $record->lost_at?->format('d M Y, h:i A') ?? '—'),
                    ]),
        ];
    }

    /**
     * $get() may hand back either the raw string value or the hydrated
     * AppointmentStage case depending on how the form state got there — see
     * LeadResource::stageIsValidated()'s docblock for the same nuance.
     */
    private static function stageIsTerminal(mixed $stage): bool
    {
        $resolved = $stage instanceof AppointmentStage ? $stage : AppointmentStage::tryFrom((string) $stage);

        return $resolved?->isTerminal() ?? false;
    }

    private static function resolveOutcome(mixed $outcome): ?AppointmentOutcome
    {
        return $outcome instanceof AppointmentOutcome ? $outcome : AppointmentOutcome::tryFrom((string) $outcome);
    }

    private static function resolveDemoMode(mixed $mode): ?DemoMode
    {
        return $mode instanceof DemoMode ? $mode : DemoMode::tryFrom((string) $mode);
    }

    private static function outcomeRequiresLead(mixed $outcome): bool
    {
        return in_array(self::resolveOutcome($outcome), [AppointmentOutcome::DemoRequired, AppointmentOutcome::ProposalRequired], true);
    }

    /**
     * Phase 3 correction: the Master BA-approved Record Outcome action —
     * the ONLY user-facing way to move a Scheduled Appointment to a real
     * business conclusion. Routes exclusively through
     * WorkflowTransitionService::transitionAppointmentOutcome(), which
     * enforces the approved outcome vocabulary and creates whichever
     * downstream record (Follow-Up/Lead/Appointment/Demo/Proposal) the
     * outcome implies — this action never mutates `stage`/`status` itself.
     */
    private static function recordOutcomeAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('recordOutcome')
            ->label('Record Outcome')
            ->icon('heroicon-o-clipboard-document-check')
            ->color('success')
            ->visible(fn (Appointment $record) => $record->status === AppointmentStatus::Scheduled && auth()->user()->can('update', $record))
            ->form([
                Forms\Components\Select::make('outcome')
                    ->options(AppointmentOutcome::class)
                    ->required()
                    ->live(),
                Forms\Components\Textarea::make('outcome_notes')
                    ->label('Outcome Notes')
                    ->rows(3)
                    ->required()
                    ->helperText('Required — what happened on this Appointment.'),
                Forms\Components\DateTimePicker::make('follow_up_at')
                    ->label('Follow Up At')
                    ->seconds(false)
                    ->visible(fn (Get $get) => self::resolveOutcome($get('outcome')) === AppointmentOutcome::FollowUpRequired)
                    ->required(fn (Get $get) => self::resolveOutcome($get('outcome')) === AppointmentOutcome::FollowUpRequired),
                Forms\Components\TextInput::make('reason')
                    ->label('Follow-Up Reason')
                    ->visible(fn (Get $get) => self::resolveOutcome($get('outcome')) === AppointmentOutcome::FollowUpRequired),
                Forms\Components\DateTimePicker::make('appointment_at')
                    ->label('Next Appointment At')
                    ->seconds(false)
                    ->visible(fn (Get $get) => self::resolveOutcome($get('outcome')) === AppointmentOutcome::AnotherAppointmentRequired)
                    ->required(fn (Get $get) => self::resolveOutcome($get('outcome')) === AppointmentOutcome::AnotherAppointmentRequired),
                Forms\Components\Select::make('lead_id')
                    ->label('Lead')
                    ->helperText('The existing Lead (requirement) this Appointment belongs to.')
                    ->options(fn (Appointment $record) => Lead::query()
                        ->where('prospect_id', $record->prospect_id)
                        ->get()
                        ->mapWithKeys(fn (Lead $lead) => [$lead->id => $lead->stage->getLabel().' — '.$lead->created_at->format('d M Y')]))
                    ->searchable()
                    ->visible(fn (Get $get) => self::outcomeRequiresLead($get('outcome')))
                    ->required(fn (Get $get) => self::outcomeRequiresLead($get('outcome'))),
                Forms\Components\DateTimePicker::make('demo_at')
                    ->label('Demo At')
                    ->seconds(false)
                    ->visible(fn (Get $get) => self::resolveOutcome($get('outcome')) === AppointmentOutcome::DemoRequired)
                    ->required(fn (Get $get) => self::resolveOutcome($get('outcome')) === AppointmentOutcome::DemoRequired),
                Forms\Components\Select::make('mode')
                    ->options(DemoMode::class)
                    ->live()
                    ->visible(fn (Get $get) => self::resolveOutcome($get('outcome')) === AppointmentOutcome::DemoRequired)
                    ->required(fn (Get $get) => self::resolveOutcome($get('outcome')) === AppointmentOutcome::DemoRequired),
                Forms\Components\TextInput::make('location')
                    ->visible(fn (Get $get) => self::resolveOutcome($get('outcome')) === AppointmentOutcome::DemoRequired && self::resolveDemoMode($get('mode')) === DemoMode::OnSite)
                    ->required(fn (Get $get) => self::resolveOutcome($get('outcome')) === AppointmentOutcome::DemoRequired && self::resolveDemoMode($get('mode')) === DemoMode::OnSite),
                Forms\Components\TextInput::make('meeting_link')
                    ->label('Meeting Link')
                    ->url()
                    ->visible(fn (Get $get) => self::resolveOutcome($get('outcome')) === AppointmentOutcome::DemoRequired && self::resolveDemoMode($get('mode')) === DemoMode::Online)
                    ->required(fn (Get $get) => self::resolveOutcome($get('outcome')) === AppointmentOutcome::DemoRequired && self::resolveDemoMode($get('mode')) === DemoMode::Online),
            ])
            ->action(function (Appointment $record, array $data) {
                try {
                    app(WorkflowTransitionService::class)->transitionAppointmentOutcome(
                        $record,
                        self::resolveOutcome($data['outcome']),
                        $data,
                    );
                } catch (LogicException $e) {
                    Notification::make()->title("Couldn't record the outcome")->body($e->getMessage())->danger()->send();
                    throw new Halt;
                }

                Notification::make()->title('Outcome recorded')->success()->send();
            });
    }

    /**
     * Extracted from table() so the Prospect View page's Appointments
     * mini-table (see App\Filament\Widgets\ProspectAppointmentsTable) can
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
            Tables\Columns\TextColumn::make('appointment_at')
                ->dateTime('d M Y, h:i A')
                ->sortable()
                ->placeholder('Not scheduled'),
            // Phase 2: a Rescheduled Appointment's legacy `stage` is
            // deliberately left unchanged (see RescheduleService), so the
            // badge must consult the normalized `status` first — otherwise
            // the History tab shows the stale stage label (e.g.
            // "Appointment Made") for a record that's actually historical/
            // Rescheduled, with no visual indication of that at all.
            Tables\Columns\TextColumn::make('stage')
                ->badge()
                ->formatStateUsing(fn (Appointment $record, AppointmentStage|string $state) => self::resolveStageBadgeLabel($record, $state))
                ->color(fn (Appointment $record, AppointmentStage|string $state) => self::resolveStageBadgeColor($record, $state))
                ->sortable(),
            Tables\Columns\TextColumn::make('is_lost')
                ->label('Current Status')
                ->badge()
                ->formatStateUsing(fn (bool $state) => $state ? 'Lost' : 'Active')
                ->color(fn (bool $state) => $state ? 'coral' : 'gray')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('assignedEmployee.name')
                ->label('Assigned To')
                ->badge()
                ->sortable(),
            Tables\Columns\TextColumn::make('stage_changed_at')
                ->label('Stage Since')
                ->dateTime('d M Y')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    /**
     * A Rescheduled Appointment's `stage` badge must say so, not show the
     * stale legacy stage label it happened to be sitting at — see the
     * `stage` column's own comment in columns() above. Extracted as a
     * named static method (mirrors FollowUpResource::resolveStatus()'s
     * precedent) so it's directly unit-testable without needing to
     * reverse-engineer Filament's column-rendering pipeline.
     */
    public static function resolveStageBadgeLabel(Appointment $record, AppointmentStage|string $state): string
    {
        if ($record->status === AppointmentStatus::Rescheduled) {
            return AppointmentStatus::Rescheduled->getLabel();
        }

        return $state instanceof AppointmentStage ? $state->getLabel() : AppointmentStage::from($state)->getLabel();
    }

    public static function resolveStageBadgeColor(Appointment $record, AppointmentStage|string $state): string|array|null
    {
        if ($record->status === AppointmentStatus::Rescheduled) {
            return AppointmentStatus::Rescheduled->getColor();
        }

        return $state instanceof AppointmentStage ? $state->getColor() : AppointmentStage::from($state)->getColor();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(static::columns())
            ->filters([
                Tables\Filters\SelectFilter::make('stage')
                    ->options(AppointmentStage::class),
                Tables\Filters\SelectFilter::make('assigned_to')
                    ->label('Assigned Employee')
                    ->relationship('assignedEmployee', 'name')
                    ->visible(fn () => auth()->user()->isAdmin()),
                Tables\Filters\TernaryFilter::make('is_lost')
                    ->label('Lost')
                    ->trueLabel('Lost only')
                    ->falseLabel('Active only')
                    ->placeholder('All appointments'),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    // Carries the tab the user is currently on through to
                    // the Edit page as ?activeTab=..., so getRedirectUrl()
                    // on EditAppointment can send them back to the same
                    // tab (History/Lost) instead of always Pending — see
                    // that page for the other half of this.
                    Tables\Actions\EditAction::make()
                        ->url(fn (Appointment $record, $livewire): string => static::getUrl('edit', [
                            'record' => $record,
                            'activeTab' => $livewire->activeTab,
                        ])),
                    Tables\Actions\Action::make('assign')
                        ->label('Reassign')
                        ->icon('heroicon-o-user-plus')
                        ->color('gray')
                        ->visible(fn (Appointment $record) => auth()->user()->can('assign', $record))
                        ->form([
                            Forms\Components\Select::make('assigned_to')
                                ->label('Assign To')
                                ->options(fn () => User::query()->pluck('name', 'id'))
                                ->required()
                                ->searchable(),
                        ])
                        ->action(fn (Appointment $record, array $data) => $record->update(['assigned_to' => $data['assigned_to']])),
                    self::recordOutcomeAction(),
                    Tables\Actions\Action::make('markLost')
                        ->label('Mark Lost')
                        ->icon('heroicon-o-x-circle')
                        ->color('coral')
                        ->requiresConfirmation(false)
                        ->visible(fn (Appointment $record) => ! $record->is_lost && auth()->user()->can('update', $record))
                        ->form([
                            Forms\Components\Textarea::make('reason')
                                ->label('Reason')
                                ->required()
                                ->rows(3)
                                ->helperText('Required — why this Appointment is being marked Lost.'),
                        ])
                        ->action(fn (Appointment $record, array $data) => $record->markLost($data['reason'])),
                    // Phase 2: the ONLY way to change appointment_at on an
                    // active Appointment — normal Edit shows it read-only
                    // (see formSchema()). This Appointment becomes
                    // Rescheduled/history; a brand new Appointment is
                    // created as the active Scheduled record.
                    Tables\Actions\Action::make('reschedule')
                        ->label('Reschedule')
                        ->icon('heroicon-o-calendar-days')
                        ->color('warning')
                        ->visible(fn (Appointment $record) => $record->status === AppointmentStatus::Scheduled && auth()->user()->can('update', $record))
                        ->form([
                            Forms\Components\DateTimePicker::make('appointment_at')
                                ->label('New Appointment At')
                                ->required()
                                ->seconds(false),
                            Forms\Components\Textarea::make('reason')
                                ->label('Reason for Reschedule')
                                ->rows(2),
                        ])
                        ->action(fn (Appointment $record, array $data) => app(RescheduleService::class)->reschedule(
                            $record,
                            ['appointment_at' => $data['appointment_at']],
                            $data['reason'] ?? null,
                        )),
                    Tables\Actions\DeleteAction::make()
                        ->visible(fn () => auth()->user()->isAdmin()),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    TableBulkActions::deselectAll(),
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()->isAdmin()),
                ]),
            ])
            ->defaultSort('appointment_at', 'asc')
            ->emptyStateHeading('Nothing on the calendar.')
            ->emptyStateDescription('Appointments appear here once a call sets one up.')
            ->emptyStateIcon('heroicon-o-calendar-days');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAppointments::route('/'),
            'create' => Pages\CreateAppointment::route('/create'),
            'view' => Pages\ViewAppointment::route('/{record}'),
            'edit' => Pages\EditAppointment::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }
}
