<?php

namespace App\Filament\Resources;

use App\Enums\ProposalOutcome;
use App\Enums\ProposalStage;
use App\Filament\Resources\ProposalResource\Pages;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Proposal processing (AGENTS.md sections 24-27, 44). Stage names/order are
 * provisional — see App\Enums\ProposalStage. One Proposal per Lead for this
 * initial version (enforced by a unique DB constraint on leads.id, see
 * AGENTS.md section 61 Question 7).
 */
class ProposalResource extends Resource
{
    protected static ?string $model = Proposal::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Proposals';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 6;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make()
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('lead_id')
                            ->label('Lead')
                            ->relationship(
                                'lead',
                                'id',
                                modifyQueryUsing: fn (Builder $query) => $query
                                    ->visibleTo(auth()->user())
                                    ->whereDoesntHave('proposal'),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Lead $record) => $record->prospect->company_name.' — '.$record->stage->getLabel())
                            ->required()
                            ->searchable()
                            ->preload()
                            ->disabled(fn (?Proposal $record) => $record !== null)
                            ->dehydrated(),
                        Forms\Components\Select::make('assigned_to')
                            ->label('Assigned Employee')
                            ->options(fn () => User::query()->pluck('name', 'id'))
                            ->default(fn () => auth()->id())
                            ->required()
                            ->searchable()
                            ->disabled(fn () => ! auth()->user()->isAdmin())
                            ->dehydrated(),
                        Forms\Components\Select::make('stage')
                            ->options(ProposalStage::class)
                            ->required()
                            ->default(ProposalStage::BeingPrepared),
                        Forms\Components\Select::make('outcome')
                            ->label('Final Outcome')
                            ->options(ProposalOutcome::class)
                            ->helperText('Leave blank while the Proposal is still in progress.'),
                        Forms\Components\TextInput::make('value')
                            ->label('Proposal Value (₹)')
                            ->numeric()
                            ->prefix('₹'),
                        Forms\Components\DatePicker::make('sent_at'),
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
                Tables\Columns\TextColumn::make('stage')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('outcome')
                    ->badge()
                    ->placeholder('In Progress')
                    ->sortable(),
                Tables\Columns\TextColumn::make('value')
                    ->label('Value')
                    ->money('INR')
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('assignedEmployee.name')
                    ->label('Assigned To')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('stage_changed_at')
                    ->label('Stage Since')
                    ->dateTime('d M Y')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_stale')
                    ->label('Stale')
                    ->boolean()
                    ->getStateUsing(fn (Proposal $record) => $record->isStale())
                    ->trueColor('danger')
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->falseColor('gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('stage')
                    ->options(ProposalStage::class),
                Tables\Filters\SelectFilter::make('outcome')
                    ->options(ProposalOutcome::class),
                Tables\Filters\SelectFilter::make('assigned_to')
                    ->label('Assigned Employee')
                    ->relationship('assignedEmployee', 'name')
                    ->visible(fn () => auth()->user()->isAdmin()),
                Tables\Filters\Filter::make('stale')
                    ->label('Stale only (20+ days, no movement)')
                    ->query(fn (Builder $query) => $query->stale()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('assign')
                    ->label('Reassign')
                    ->icon('heroicon-o-user-plus')
                    ->color('gray')
                    ->visible(fn (Proposal $record) => auth()->user()->can('assign', $record))
                    ->form([
                        Forms\Components\Select::make('assigned_to')
                            ->label('Assign To')
                            ->options(fn () => User::query()->pluck('name', 'id'))
                            ->required()
                            ->searchable(),
                    ])
                    ->action(fn (Proposal $record, array $data) => $record->update(['assigned_to' => $data['assigned_to']])),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProposals::route('/'),
            'create' => Pages\CreateProposal::route('/create'),
            'view' => Pages\ViewProposal::route('/{record}'),
            'edit' => Pages\EditProposal::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }
}
