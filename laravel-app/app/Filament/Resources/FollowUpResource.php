<?php

namespace App\Filament\Resources;

use App\Enums\FollowUpStatus;
use App\Filament\Resources\FollowUpResource\Pages;
use App\Models\FollowUp;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Follow-Ups panel (AGENTS.md section 17). Mostly populated automatically
 * by App\Services\CallRoutingService from No Answer / Switched Off / Not
 * Reachable / Callback Requested calls, but can also be created directly.
 */
class FollowUpResource extends Resource
{
    protected static ?string $model = FollowUp::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationLabel = 'Follow-Ups';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 3;

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
                        Forms\Components\DateTimePicker::make('follow_up_at')
                            ->seconds(false),
                        Forms\Components\TextInput::make('reason')
                            ->maxLength(255),
                        Forms\Components\Select::make('status')
                            ->options(FollowUpStatus::class)
                            ->required()
                            ->default(FollowUpStatus::Pending),
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
                Tables\Columns\TextColumn::make('prospect.company_name')
                    ->label('Company')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('reason')
                    ->searchable(),
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
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(FollowUpStatus::class),
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Employee')
                    ->relationship('responsibleEmployee', 'name')
                    ->visible(fn () => auth()->user()->isAdmin()),
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
            ->defaultSort('follow_up_at', 'asc');
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
