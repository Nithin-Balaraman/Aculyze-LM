<?php

namespace App\Filament\Resources;

use App\Enums\DemoMode;
use App\Enums\DemoNextAction;
use App\Enums\DemoOutcome;
use App\Enums\DemoStatus;
use App\Filament\Resources\DemoResource\Pages;
use App\Models\Demo;
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
 * Phase 3: Demo becomes first-class, user-facing functionality. Every Demo
 * belongs to an existing Lead and is created ONLY through a valid workflow
 * transition (Follow-Up/Appointment/Lead/Proposal -> Demo, or a completed
 * Demo's own "Schedule Another Demo" outcome) via
 * WorkflowTransitionService::transitionToDemo() — never through a generic
 * standalone "Create Demo" flow. This resource therefore has no 'create'
 * page/route; it provides List, View, limited Edit of non-workflow
 * descriptive fields, Reschedule, and Record Outcome.
 */
class DemoResource extends Resource
{
    protected static ?string $model = Demo::class;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-bar';

    protected static ?string $navigationLabel = 'Demos';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 6;

    /**
     * Descriptive fields only — demo_at is read-only here (Reschedule is
     * the only way to change it, per GuardsScheduleAgainstDirectEdit on the
     * model), and status/outcome/next_action are never edited through a
     * generic form — only via the dedicated Record Outcome action below.
     */
    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Demo Details')
                ->columns(2)
                ->schema([
                    Forms\Components\Placeholder::make('lead_display')
                        ->label('Lead')
                        ->content(fn (Demo $record) => $record->lead?->prospect?->company_name ?? '—'),
                    Forms\Components\DateTimePicker::make('demo_at')
                        ->label('Demo At')
                        ->seconds(false)
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Use the Reschedule action to change this.'),
                    Forms\Components\Select::make('mode')
                        ->options(DemoMode::class)
                        ->required()
                        ->live(),
                    Forms\Components\TextInput::make('location')
                        ->visible(fn (Get $get) => self::resolveMode($get('mode')) === DemoMode::OnSite)
                        ->required(fn (Get $get) => self::resolveMode($get('mode')) === DemoMode::OnSite),
                    Forms\Components\TextInput::make('meeting_link')
                        ->label('Meeting Link')
                        ->url()
                        ->visible(fn (Get $get) => self::resolveMode($get('mode')) === DemoMode::Online)
                        ->required(fn (Get $get) => self::resolveMode($get('mode')) === DemoMode::Online),
                    Forms\Components\TextInput::make('attendees')
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('product_service')
                        ->label('Product / Service'),
                    Forms\Components\TextInput::make('purpose'),
                    Forms\Components\Textarea::make('notes')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    private static function resolveMode(mixed $mode): ?DemoMode
    {
        return $mode instanceof DemoMode ? $mode : DemoMode::tryFrom((string) $mode);
    }

    private static function resolveOutcome(mixed $outcome): ?DemoOutcome
    {
        return $outcome instanceof DemoOutcome ? $outcome : DemoOutcome::tryFrom((string) $outcome);
    }

    /** Safe wrapper — DemoOutcome::deterministicNextAction() throws for a non-deterministic outcome, so this never calls it unguarded. */
    private static function deterministicNextActionOrNull(mixed $outcome): ?DemoNextAction
    {
        $resolved = self::resolveOutcome($outcome);

        if ($resolved === null || ! $resolved->isNextActionDeterministic()) {
            return null;
        }

        return $resolved->deterministicNextAction();
    }

    /** The effective next_action for a given form state — the deterministic one if the outcome has one, otherwise whatever the user explicitly picked. */
    private static function effectiveNextAction(mixed $outcome, mixed $nextAction): ?DemoNextAction
    {
        return self::deterministicNextActionOrNull($outcome)
            ?? ($nextAction instanceof DemoNextAction ? $nextAction : DemoNextAction::tryFrom((string) $nextAction));
    }

    /**
     * @return array<int, Tables\Columns\Column>
     */
    public static function columns(): array
    {
        return [
            Tables\Columns\TextColumn::make('lead.prospect.company_name')
                ->label('Company')
                ->searchable()
                ->sortable(),
            Tables\Columns\TextColumn::make('demo_at')
                ->dateTime('d M Y, h:i A')
                ->sortable(),
            Tables\Columns\TextColumn::make('mode')
                ->badge(),
            Tables\Columns\TextColumn::make('status')
                ->badge()
                ->sortable(),
            Tables\Columns\TextColumn::make('outcome')
                ->badge()
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('assignedEmployee.name')
                ->label('Employee')
                ->badge()
                ->sortable()
                ->visible(fn () => auth()->user()->isAdmin()),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(static::columns())
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(DemoStatus::class),
                Tables\Filters\SelectFilter::make('assigned_to')
                    ->label('Assigned Employee')
                    ->relationship('assignedEmployee', 'name')
                    ->visible(fn () => auth()->user()->isAdmin()),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\Action::make('assign')
                        ->label('Reassign')
                        ->icon('heroicon-o-user-plus')
                        ->color('gray')
                        ->visible(fn (Demo $record) => auth()->user()->can('assign', $record))
                        ->form([
                            Forms\Components\Select::make('assigned_to')
                                ->label('Assign To')
                                ->options(fn () => User::query()->pluck('name', 'id'))
                                ->required()
                                ->searchable(),
                        ])
                        ->action(fn (Demo $record, array $data) => $record->update(['assigned_to' => $data['assigned_to']])),
                    // Phase 3: the ONLY way to change demo_at on an active
                    // Demo — normal Edit shows it read-only. This Demo
                    // becomes Rescheduled/history; a brand new Demo is
                    // created as the active Scheduled record.
                    Tables\Actions\Action::make('reschedule')
                        ->label('Reschedule')
                        ->icon('heroicon-o-calendar-days')
                        ->color('warning')
                        ->visible(fn (Demo $record) => $record->status === DemoStatus::Scheduled && auth()->user()->can('update', $record))
                        ->form([
                            Forms\Components\DateTimePicker::make('demo_at')
                                ->label('New Demo At')
                                ->required()
                                ->seconds(false),
                            Forms\Components\Textarea::make('reason')
                                ->label('Reason for Reschedule')
                                ->rows(2),
                        ])
                        ->action(fn (Demo $record, array $data) => app(RescheduleService::class)->reschedule(
                            $record,
                            ['demo_at' => $data['demo_at']],
                            $data['reason'] ?? null,
                        )),
                    self::recordOutcomeAction(),
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
            ->defaultSort('demo_at', 'asc')
            ->emptyStateHeading('No Demos scheduled.')
            ->emptyStateDescription('A Demo is scheduled from a Lead, Appointment, Follow-Up, or Proposal — never created directly here.')
            ->emptyStateIcon('heroicon-o-presentation-chart-bar');
    }

    /**
     * The primary new Phase 3 surface: records a Demo's outcome and, where
     * the outcome/next_action implies one, its downstream action (another
     * Demo, a Follow-Up, back to the Lead for clarification, or starting a
     * Proposal) — entirely through WorkflowTransitionService::
     * transitionDemoOutcome(), which already enforces the full approved
     * determinism table (deterministic outcomes auto-set next_action;
     * non-deterministic ones require an explicit, validated choice).
     */
    private static function recordOutcomeAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('recordOutcome')
            ->label('Record Outcome')
            ->icon('heroicon-o-clipboard-document-check')
            ->color('success')
            ->visible(fn (Demo $record) => $record->status === DemoStatus::Scheduled && auth()->user()->can('update', $record))
            ->form([
                Forms\Components\Select::make('outcome')
                    ->label('Outcome')
                    ->options(DemoOutcome::class)
                    ->required()
                    ->live(),
                // Only rendered as a real choice for non-deterministic
                // outcomes (Interested/OK, Correction Needed, Other) — for
                // every other outcome the next action is fully determined
                // by DemoOutcome::deterministicNextAction() and is never
                // asked for, since WorkflowTransitionService::
                // transitionDemoOutcome() derives it itself when omitted.
                Forms\Components\Select::make('next_action')
                    ->label('Next Action')
                    ->options(fn (Get $get) => collect(self::resolveOutcome($get('outcome'))?->allowedNextActions() ?? [])
                        ->mapWithKeys(fn (DemoNextAction $a) => [$a->value => $a->getLabel()]))
                    ->required()
                    ->live()
                    ->visible(fn (Get $get) => self::resolveOutcome($get('outcome')) !== null && self::deterministicNextActionOrNull($get('outcome')) === null),
                Forms\Components\Textarea::make('correction_comments')
                    ->label('Correction / Customer Comments')
                    ->rows(2)
                    ->visible(fn (Get $get) => self::resolveOutcome($get('outcome')) === DemoOutcome::CorrectionNeeded)
                    ->required(fn (Get $get) => self::resolveOutcome($get('outcome')) === DemoOutcome::CorrectionNeeded),
                Forms\Components\Textarea::make('notes')
                    ->rows(2)
                    ->visible(fn (Get $get) => self::resolveOutcome($get('outcome')) === DemoOutcome::Other)
                    ->required(fn (Get $get) => self::resolveOutcome($get('outcome')) === DemoOutcome::Other),
                Forms\Components\DateTimePicker::make('demo_at')
                    ->label('New Demo At')
                    ->seconds(false)
                    ->visible(fn (Get $get) => self::effectiveNextAction($get('outcome'), $get('next_action')) === DemoNextAction::ScheduleAnotherDemo)
                    ->required(fn (Get $get) => self::effectiveNextAction($get('outcome'), $get('next_action')) === DemoNextAction::ScheduleAnotherDemo),
                Forms\Components\DateTimePicker::make('follow_up_at')
                    ->label('Follow Up At')
                    ->seconds(false)
                    ->visible(fn (Get $get) => self::effectiveNextAction($get('outcome'), $get('next_action')) === DemoNextAction::CreateFollowUp)
                    ->required(fn (Get $get) => self::effectiveNextAction($get('outcome'), $get('next_action')) === DemoNextAction::CreateFollowUp),
                Forms\Components\Textarea::make('clarification_notes')
                    ->label('Clarification Notes')
                    ->rows(2)
                    ->visible(fn (Get $get) => self::effectiveNextAction($get('outcome'), $get('next_action')) === DemoNextAction::ReturnToLeadForClarification),
            ])
            ->action(function (Demo $record, array $data) {
                $outcome = DemoOutcome::from($data['outcome']);

                try {
                    app(WorkflowTransitionService::class)->transitionDemoOutcome($record, $outcome, array_filter([
                        'next_action' => filled($data['next_action'] ?? null) ? DemoNextAction::from($data['next_action']) : null,
                        'correction_comments' => $data['correction_comments'] ?? null,
                        'notes' => $data['notes'] ?? null,
                        'demo_at' => $data['demo_at'] ?? null,
                        'mode' => $record->mode,
                        'follow_up_at' => $data['follow_up_at'] ?? null,
                        'reason' => $outcome->getLabel(),
                        'clarification_notes' => $data['clarification_notes'] ?? null,
                    ], fn ($value) => $value !== null));
                } catch (LogicException $e) {
                    Notification::make()->title("Couldn't record this Demo's outcome")->body($e->getMessage())->danger()->send();
                    throw new Halt;
                }

                Notification::make()->title('Demo outcome recorded')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDemos::route('/'),
            'view' => Pages\ViewDemo::route('/{record}'),
            'edit' => Pages\EditDemo::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }
}
