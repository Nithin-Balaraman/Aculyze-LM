<?php

namespace App\Filament\Resources;

use App\Enums\LeadStage;
use App\Enums\LeadTemperature;
use App\Filament\Resources\LeadResource\Pages;
use App\Models\Lead;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                        Forms\Components\Select::make('stage')
                            ->options(LeadStage::class)
                            ->required()
                            ->default(LeadStage::RequirementCollection),
                        Forms\Components\Select::make('temperature')
                            ->options(LeadTemperature::class)
                            ->required()
                            ->default(LeadTemperature::Warm),
                        Forms\Components\Textarea::make('requirement_details')
                            ->rows(3)
                            ->columnSpanFull(),
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
                Tables\Columns\TextColumn::make('temperature')
                    ->badge()
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
                    ->getStateUsing(fn (Lead $record) => $record->isStale())
                    ->trueColor('danger')
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->falseColor('gray'),
            ])
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
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
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
                    ->visible(fn (Lead $record) => $record->stage->isEligibleForProposal() && $record->proposal === null)
                    ->url(fn (Lead $record) => \App\Filament\Resources\ProposalResource::getUrl('create', ['lead_id' => $record->id])),
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
