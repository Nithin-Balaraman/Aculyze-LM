<?php

namespace App\Filament\Resources;

use App\Enums\AppointmentStage;
use App\Filament\Resources\AppointmentResource\Pages;
use App\Models\Appointment;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                        Forms\Components\Select::make('assigned_to')
                            ->label('Assigned Employee')
                            ->options(fn () => User::query()->pluck('name', 'id'))
                            ->default(fn () => auth()->id())
                            ->required()
                            ->searchable()
                            ->disabled(fn () => ! auth()->user()->isAdmin())
                            ->dehydrated(),
                        Forms\Components\DateTimePicker::make('appointment_at')
                            ->seconds(false),
                        Forms\Components\Select::make('stage')
                            ->options(AppointmentStage::class)
                            ->required()
                            ->default(AppointmentStage::AppointmentMade),
                        Forms\Components\Textarea::make('meeting_notes')
                            ->rows(3)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('outcome_notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('prospect.company_name')
                    ->label('Company')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('appointment_at')
                    ->dateTime('d M Y, h:i A')
                    ->sortable()
                    ->placeholder('Not scheduled'),
                Tables\Columns\TextColumn::make('stage')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('is_lost')
                    ->label('Current Status')
                    ->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Lost' : 'Active')
                    ->color(fn (bool $state) => $state ? 'coral' : 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('assignedEmployee.name')
                    ->label('Assigned To')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('stage_changed_at')
                    ->label('Stage Since')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
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
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
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
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
