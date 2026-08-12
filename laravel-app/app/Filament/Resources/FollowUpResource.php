<?php

namespace App\Filament\Resources;

use App\Enums\CallOutcome;
use App\Enums\FollowUpStatus;
use App\Filament\Resources\FollowUpResource\Pages;
use App\Models\CallRecord;
use App\Models\FollowUp;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
                // Pending vs. History is now owned by the page's tabs (UX
                // Fixes Batch Issue 3) rather than this filter, so there is
                // only ever one place that decides what "pending" means.
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Employee')
                    ->relationship('responsibleEmployee', 'name')
                    ->visible(fn () => auth()->user()->isAdmin()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
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
                            ->helperText('You reached them — log what happened on this call, same as logging any other call.'),
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
                    ->action(fn (FollowUp $record) => $record->update(['status' => FollowUpStatus::Cancelled])),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn () => auth()->user()->isAdmin()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()->isAdmin()),
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
