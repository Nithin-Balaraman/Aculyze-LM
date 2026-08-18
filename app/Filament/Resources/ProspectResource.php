<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProspectResource\Pages;
use App\Models\Prospect;
use App\Models\User;
use App\Support\TableBulkActions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Database module (AGENTS.md section 10) — the master list of
 * companies/contacts. Everything in the sales workflow starts from here.
 */
class ProspectResource extends Resource
{
    protected static ?string $model = Prospect::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Database';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'company_name';

    public static function form(Form $form): Form
    {
        return $form->schema(static::formSchema());
    }

    /**
     * Extracted from form() so other resources can open the exact same
     * Prospect creation fields inline (see CallRecordResource's Company
     * field — the "+ Create new company…" search-dropdown row and the
     * standard createOptionForm "+" button both reuse this).
     *
     * @return array<int, Forms\Components\Component>
     */
    public static function formSchema(): array
    {
        return [
            Forms\Components\Section::make('Company')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('company_name')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('contact_person')->maxLength(255),
                    Forms\Components\TextInput::make('designation')->maxLength(255),
                    Forms\Components\TextInput::make('telephone')
                        ->tel()
                        ->maxLength(20),
                    Forms\Components\TextInput::make('mobile')
                        ->tel()
                        ->maxLength(20),
                    Forms\Components\TextInput::make('email')
                        ->email()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('website')
                        ->url()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('industry')->maxLength(255),
                    Forms\Components\TextInput::make('source')->maxLength(255),
                ]),
            Forms\Components\Section::make('Location')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('address')
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('locality')->maxLength(255),
                    Forms\Components\TextInput::make('city')->maxLength(255),
                    Forms\Components\TextInput::make('state')->maxLength(255),
                    Forms\Components\TextInput::make('pincode')->maxLength(20),
                ]),
            Forms\Components\Section::make('Ownership')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('assigned_to')
                        ->label('Assigned Employee')
                        ->relationship('assignedEmployee', 'name')
                        ->default(fn () => auth()->id())
                        ->required()
                        ->searchable()
                        ->preload()
                        // Employees may not hand their own prospects to
                        // someone else — only Admin assigns/reassigns
                        // (AGENTS.md sections 11, 28).
                        ->disabled(fn () => ! auth()->user()->isAdmin())
                        ->dehydrated(),
                ]),
            Forms\Components\Section::make('Notes')
                ->schema([
                    Forms\Components\Textarea::make('notes')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('company_name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('contact_person')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('telephone')
                    ->label('Telephone')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('industry')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('city')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('address')
                    ->searchable()
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('locality')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('assignedEmployee.name')
                    ->label('Assigned To')
                    ->badge()
                    ->placeholder('Unassigned')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('assigned_to')
                    ->label('Assigned Employee')
                    ->relationship('assignedEmployee', 'name')
                    ->visible(fn () => auth()->user()->isAdmin()),
                Tables\Filters\SelectFilter::make('industry')
                    ->options(fn () => Prospect::query()->whereNotNull('industry')->distinct()->pluck('industry', 'industry')),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\Action::make('assign')
                        ->label('Reassign')
                        ->icon('heroicon-o-user-plus')
                        ->color('gray')
                        ->visible(fn (Prospect $record) => auth()->user()->can('assign', $record))
                        ->form([
                            Forms\Components\Select::make('assigned_to')
                                ->label('Assign To')
                                ->options(fn () => User::query()->pluck('name', 'id'))
                                ->required()
                                ->searchable(),
                        ])
                        ->action(function (Prospect $record, array $data) {
                            $record->update(['assigned_to' => $data['assigned_to']]);
                        }),
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
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No prospects yet.')
            ->emptyStateDescription('Add a company to start the pipeline — every call starts here.')
            ->emptyStateIcon('heroicon-o-building-office-2');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProspects::route('/'),
            'create' => Pages\CreateProspect::route('/create'),
            'view' => Pages\ViewProspect::route('/{record}'),
            'edit' => Pages\EditProspect::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }
}
