<?php

namespace App\Filament\Resources;

use App\Enums\LeadStage;
use App\Enums\LeadTemperature;
use App\Filament\Resources\LeadResource\Pages;
use App\Models\Lead;
use App\Models\User;
use App\Support\DeletionGuard;
use App\Support\TableBulkActions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Lead Sheet (AGENTS.md sections 20-23, 43). Stage names/order are
 * provisional — see App\Enums\LeadStage. Stale alert logic lives on the
 * Lead model (isStale()/scopeStale()) and is reused by both the dashboards
 * and this resource's table filter.
 */
class LeadResource extends Resource
{
    protected static ?string $model = Lead::class;

    protected static ?string $navigationIcon = 'heroicon-o-fire';

    protected static ?string $navigationLabel = 'Leads';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 5;

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
                        Forms\Components\Select::make('stage')
                            ->options(LeadStage::class)
                            ->required()
                            ->default(LeadStage::RequirementCollection)
                            ->live(),
                        Forms\Components\Select::make('temperature')
                            ->options(LeadTemperature::class)
                            ->required()
                            ->default(LeadTemperature::Warm),
                        Forms\Components\Textarea::make('requirement_details')
                            ->rows(3)
                            ->columnSpanFull(),
                        // Validated Lead / Create Proposal batch: Notes
                        // becomes required — visibly (the asterisk, via
                        // ->required()) and at save time — the moment Stage
                        // is Validated, and reverts the instant it isn't.
                        // Plain ->required() alone would accept a
                        // whitespace-only value (Laravel's `required` rule
                        // doesn't trim), so the extra closure rule below
                        // catches that case with the same message.
                        //
                        // $get('stage') is NOT reliably a string here: once
                        // a real Select interaction round-trips through
                        // Livewire, Filament rehydrates it as the actual
                        // LeadStage enum case (only a raw form-state seed —
                        // e.g. a test's fillForm() — hands back a string),
                        // so both representations must be handled.
                        Forms\Components\Textarea::make('notes')
                            ->label('Notes / Remarks')
                            ->rows(3)
                            ->columnSpanFull()
                            ->required(fn (Get $get) => self::stageIsValidated($get('stage')))
                            ->validationMessages([
                                'required' => 'Notes are required when the Lead stage is Validated.',
                            ])
                            ->rule(
                                fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                                    if (self::stageIsValidated($get('stage')) && blank($value)) {
                                        $fail('Notes are required when the Lead stage is Validated.');
                                    }
                                },
                            ),
                    ]),
                // is_lost/lost_reason/lost_at/lost_at_stage are deliberately
                // absent from Lead's own $fillable (only markLost() sets
                // them, via forceFill() — see its own docblock: "Lost is an
                // outcome applied on top of wherever the Lead currently
                // is"), so these are read-only Placeholders, never an
                // editable field — there was previously no way to see WHY a
                // Lost Lead was marked Lost anywhere on this page at all.
                Forms\Components\Section::make('Lost')
                    ->visible(fn (?Lead $record) => $record?->is_lost ?? false)
                    ->columns(3)
                    ->schema([
                        Forms\Components\Placeholder::make('lost_reason_display')
                            ->label('Reason')
                            ->columnSpanFull()
                            ->content(fn (Lead $record) => $record->lost_reason ?: '—'),
                        Forms\Components\Placeholder::make('lost_at_stage_display')
                            ->label('Stage At Time Of Loss')
                            ->content(fn (Lead $record) => $record->lost_at_stage?->getLabel() ?? '—'),
                        Forms\Components\Placeholder::make('lost_at_display')
                            ->label('Lost At')
                            ->content(fn (Lead $record) => $record->lost_at?->format('d M Y, h:i A') ?? '—'),
                    ]),
        ];
    }

    /**
     * $get() may hand back either the raw string value or the hydrated
     * LeadStage case depending on how the form state got there — see the
     * comment above the `notes` field.
     */
    private static function stageIsValidated(mixed $stage): bool
    {
        return ($stage instanceof LeadStage ? $stage : LeadStage::tryFrom((string) $stage)) === LeadStage::Validated;
    }

    /**
     * Extracted from table() so the Prospect View page's Leads mini-table
     * (see App\Filament\Widgets\ProspectLeadsTable) can reuse the exact
     * same column set rather than duplicating it — matches the
     * ProspectResource::formSchema() precedent for form fields.
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
            Tables\Columns\TextColumn::make('stage')
                ->badge()
                ->sortable(),
            Tables\Columns\TextColumn::make('temperature')
                ->badge()
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
            Tables\Columns\IconColumn::make('is_stale')
                ->label('Stale')
                ->boolean()
                ->getStateUsing(fn (Lead $record) => $record->isStale())
                ->trueColor('coral')
                ->trueIcon('heroicon-o-exclamation-triangle')
                ->falseIcon('heroicon-o-check-circle')
                ->falseColor('gray'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(static::columns())
            ->filters([
                Tables\Filters\SelectFilter::make('stage')
                    ->options(LeadStage::class),
                Tables\Filters\SelectFilter::make('temperature')
                    ->options(LeadTemperature::class),
                Tables\Filters\SelectFilter::make('assigned_to')
                    ->label('Assigned Employee')
                    ->relationship('assignedEmployee', 'name')
                    ->visible(fn () => auth()->user()->isAdmin()),
                Tables\Filters\Filter::make('stale')
                    ->label('Stale only (30+ days, no movement)')
                    ->query(fn (Builder $query) => $query->stale()),
                Tables\Filters\TernaryFilter::make('is_lost')
                    ->label('Lost')
                    ->trueLabel('Lost only')
                    ->falseLabel('Active only')
                    ->placeholder('All leads'),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    // Carries the tab the user is currently on through to
                    // the Edit page as ?activeTab=..., so getRedirectUrl()
                    // on EditLead can send them back to the same tab
                    // (History/Lost) instead of always Pending — see that
                    // page for the other half of this.
                    Tables\Actions\EditAction::make()
                        ->url(fn (Lead $record, $livewire): string => static::getUrl('edit', [
                            'record' => $record,
                            'activeTab' => $livewire->activeTab,
                        ])),
                    Tables\Actions\Action::make('assign')
                        ->label('Reassign')
                        ->icon('heroicon-o-user-plus')
                        ->color('gray')
                        ->visible(fn (Lead $record) => auth()->user()->can('assign', $record))
                        ->form([
                            Forms\Components\Select::make('assigned_to')
                                ->label('Assign To')
                                ->options(fn () => User::query()->pluck('name', 'id'))
                                ->required()
                                ->searchable(),
                        ])
                        ->action(fn (Lead $record, array $data) => $record->update(['assigned_to' => $data['assigned_to']])),
                    Tables\Actions\Action::make('createProposal')
                        ->label('Create Proposal')
                        ->icon('heroicon-o-document-text')
                        ->color('success')
                        // Phase 3: eligibility now ALSO recognizes normalized
                        // LeadStatus::ProposalRequired — the exact normalized
                        // equivalent of legacy stage=Validated (see
                        // Lead::booted()'s notes guard for the same
                        // reasoning) — so a Lead that reaches
                        // ProposalRequired via the new WorkflowTransitionService-
                        // driven workflow (whose legacy `stage` is
                        // deliberately left frozen) can still start a
                        // Proposal. The legacy stage-driven check is kept,
                        // not replaced, so existing Validated Leads are
                        // unaffected.
                        ->visible(fn (Lead $record) => ! $record->is_lost
                            && ($record->stage->isEligibleForProposal() || $record->status === \App\Enums\LeadStatus::ProposalRequired)
                            && $record->hasMeaningfulNotes()
                            && $record->proposal === null
                            && auth()->user()->can('update', $record))
                        ->url(fn (Lead $record) => ProposalResource::getUrl('create', ['lead_id' => $record->id])),
                    Tables\Actions\Action::make('markLost')
                        ->label('Mark Lost')
                        ->icon('heroicon-o-x-circle')
                        ->color('coral')
                        ->requiresConfirmation(false)
                        ->visible(fn (Lead $record) => ! $record->is_lost && auth()->user()->can('update', $record))
                        ->form([
                            Forms\Components\Textarea::make('reason')
                                ->label('Reason')
                                ->required()
                                ->rows(3)
                                ->helperText('Required — why this Lead is being marked Lost.'),
                        ])
                        ->action(fn (Lead $record, array $data) => $record->markLost($data['reason'])),
                    Tables\Actions\DeleteAction::make()
                        ->visible(fn () => auth()->user()->isAdmin())
                        ->before(fn (Lead $record) => DeletionGuard::guardRecord($record, 'lead')),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    TableBulkActions::deselectAll(),
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()->isAdmin())
                        ->before(fn (Collection $records) => DeletionGuard::guardRecords($records, 'leads', fn (Lead $lead) => $lead->prospect->company_name)),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No leads right now.')
            ->emptyStateDescription('A "Requirement Identified" call turns into a Lead here automatically.')
            ->emptyStateIcon('heroicon-o-fire');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLeads::route('/'),
            'create' => Pages\CreateLead::route('/create'),
            'view' => Pages\ViewLead::route('/{record}'),
            'edit' => Pages\EditLead::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }
}
