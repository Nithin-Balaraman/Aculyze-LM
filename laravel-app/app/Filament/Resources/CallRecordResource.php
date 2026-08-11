<?php

namespace App\Filament\Resources;

use App\Enums\CallOutcome;
use App\Filament\Resources\CallRecordResource\Pages;
use App\Models\CallRecord;
use App\Models\Prospect;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Call Details')
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
                        Forms\Components\TextInput::make('phone_called')
                            ->tel()
                            ->maxLength(20),
                        Forms\Components\Toggle::make('callback_required')
                            ->live(),
                        Forms\Components\DateTimePicker::make('callback_at')
                            ->seconds(false)
                            ->visible(fn (Forms\Get $get) => $get('callback_required')),
                    ]),
                Forms\Components\Section::make('Notes')
                    ->schema([
                        Forms\Components\Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
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
                    ->sortable(),
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
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
