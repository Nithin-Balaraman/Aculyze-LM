<?php

namespace App\Filament\Resources;

use App\Enums\CallOutcome;
use App\Filament\Resources\CallRecordResource\Pages;
use App\Models\CallRecord;
use App\Models\Prospect;
use App\Support\DeletionGuard;
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
        return $form
            ->schema([
                Forms\Components\Section::make('Call Details')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('prospect_id')
                            ->label('Company')
                            ->required()
                            // Deliberately NOT ->relationship() and NOT
                            // ->createOptionForm() — both were tried and
                            // both broke this specific requirement (an
                            // always-present synthetic "create new" row the
                            // user can click *in the dropdown itself*):
                            // createOptionForm's internals crash without
                            // relationship() (they unconditionally walk
                            // getRelationshipName()), but WITH
                            // relationship() restored, selecting the
                            // sentinel value silently did nothing in the
                            // browser — relationship-mode Select appears to
                            // validate/hydrate a selected option against
                            // the real `prospect` relationship before
                            // committing the state change, so a value with
                            // no matching Prospect row never reaches
                            // afterStateUpdated() at all client-side (even
                            // though it works when state is set
                            // programmatically in a test). A plain
                            // ->suffixAction() sidesteps both — it's a
                            // generic field-adjacent action with no
                            // relationship coupling at all.
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
                            ->afterStateUpdated(function ($state, Set $set, $livewire) {
                                if ($state !== self::CREATE_NEW_PROSPECT) {
                                    return;
                                }

                                // Reset the field itself — the sentinel is
                                // never a real selection — then open the
                                // same modal the suffix "+" action below
                                // opens, via Filament's standard trigger
                                // for a field-scoped action.
                                $set('prospect_id', null);
                                $livewire->mountFormComponentAction('prospect_id', 'createProspect');
                            })
                            ->suffixAction(
                                Forms\Components\Actions\Action::make('createProspect')
                                    ->icon('heroicon-o-plus')
                                    ->modalHeading('Add Company to Database')
                                    ->form(ProspectResource::formSchema())
                                    ->action(function (array $data, Set $set) {
                                        $data['created_by'] = auth()->id();
                                        $prospect = Prospect::create($data);

                                        $set('prospect_id', $prospect->getKey());
                                    }),
                            ),
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
                        Forms\Components\Toggle::make('callback_required')
                            ->live(),
                        Forms\Components\DateTimePicker::make('callback_at')
                            ->seconds(false)
                            ->visible(fn (Forms\Get $get) => $get('callback_required')),
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
                        // Others is a catch-all with no defined next action
                        // (it routes nowhere — see CallOutcome::
                        // routesNowhere()), so Notes becomes the only record
                        // of what actually happened and must be filled in.
                        // Mirrors the same required()+rule() pairing
                        // LeadResource uses for "Notes required when
                        // Validated" — plain required() alone would accept
                        // a whitespace-only value.
                        Forms\Components\Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull()
                            ->required(fn (Get $get) => self::outcomeIsOthers($get('outcome')))
                            ->validationMessages([
                                'required' => 'Notes are required when the outcome is Others.',
                            ])
                            ->rule(
                                fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                                    if (self::outcomeIsOthers($get('outcome')) && blank($value)) {
                                        $fail('Notes are required when the outcome is Others.');
                                    }
                                },
                            ),
                    ]),
            ]);
    }

    /**
     * $get() may hand back either the raw string value or the hydrated
     * CallOutcome case depending on how the form state got there — see the
     * comment above the `notes` field (mirrors LeadResource::
     * stageIsValidated()).
     */
    private static function outcomeIsOthers(mixed $outcome): bool
    {
        return ($outcome instanceof CallOutcome ? $outcome : CallOutcome::tryFrom((string) $outcome)) === CallOutcome::Others;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
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
                Tables\Columns\IconColumn::make('callback_required')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('notes')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
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
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(fn (CallRecord $record) => DeletionGuard::guardRecord($record, 'call record')),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
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
