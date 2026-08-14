<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Exceptions\EmployeeDeletionFailedException;
use App\Filament\Pages\EmployeeDashboard;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Services\EmployeeDeletionService;
use App\Support\DeletionGuard;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Exceptions\Halt;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;

/**
 * Employee Management. Admin-only — see canAccess() below. Creating a User
 * here immediately grants them login + their own dashboard; there is no
 * separate manual "set up dashboard" step (AGENTS.md section 8).
 *
 * UX Fixes Batch Issue 5: single-employee deletion no longer uses the
 * generic App\Support\DeletionGuard dead-end (which just blocks and offers
 * no way forward) — see deletionAction() below for the guided cleanup/
 * reassignment flow, backed by App\Services\EmployeeDeletionService. Bulk
 * deletion still uses DeletionGuard::guardRecords() unchanged: it only ever
 * allows deleting employees with zero dependencies in one batch, which is
 * the same "nothing to clean up" case Section 5.7 says can stay simple —
 * anything with real dependencies must go through the guided one-at-a-time
 * flow instead.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Employees';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Employee Details')
                    ->columns(2)
                    ->schema([
                        Forms\Components\FileUpload::make('avatar')
                            ->disk('avatars')
                            ->visibility('public')
                            ->avatar()
                            ->image()
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Forms\Components\Select::make('role')
                            ->options(UserRole::class)
                            ->required()
                            ->default(UserRole::Employee),
                        Forms\Components\TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->required(fn (string $context): bool => $context === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                            ->maxLength(255)
                            ->helperText('Leave blank to keep the current password when editing.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('avatar')
                    ->label('')
                    ->disk('avatars')
                    ->circular()
                    ->defaultImageUrl(fn (User $record) => $record->getFilamentAvatarUrl()),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('role')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Joined')
                    ->dateTime('d M Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->options(UserRole::class),
            ])
            ->actions([
                Tables\Actions\Action::make('dashboard')
                    ->label('Dashboard')
                    ->icon('heroicon-o-chart-bar')
                    ->url(fn (User $record) => EmployeeDashboard::getUrl(['employee' => $record->id]))
                    ->color('gray'),
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                static::tableDeleteAction(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->before(fn (Collection $records) => DeletionGuard::guardRecords($records, 'employees', fn (User $user) => $user->name)),
                ]),
            ])
            ->emptyStateHeading('No employees yet.')
            ->emptyStateDescription('Add your team so they can start logging calls and get their own dashboard.')
            ->emptyStateIcon('heroicon-o-users');
    }

    /**
     * The guided employee-deletion action for a table row. Table row
     * actions and page header actions are different base classes
     * (Tables\Actions\Action vs. Filament\Actions\Action) even though they
     * share the same fluent API, so this and headerDeleteAction() below
     * both just wire the same static form/action logic into whichever
     * class their context needs — see EditUser for the header version.
     */
    public static function tableDeleteAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('delete')
            ->label('Delete')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn () => auth()->user()?->isAdmin() ?? false)
            ->requiresConfirmation()
            ->modalHeading(fn (User $record) => "Delete {$record->name}?")
            ->modalSubmitActionLabel('Delete')
            ->form(fn (User $record) => static::deletionFormSchema($record))
            ->action(fn (User $record, array $data) => static::runGuidedDeletion($record, $data));
    }

    /**
     * Same guided flow, wired as a Filament\Actions\Action for use as a
     * page header action (EditUser's "Delete" button).
     */
    public static function headerDeleteAction(): Action
    {
        return Action::make('delete')
            ->label('Delete')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn () => auth()->user()?->isAdmin() ?? false)
            ->requiresConfirmation()
            ->modalHeading(fn (User $record) => "Delete {$record->name}?")
            ->modalSubmitActionLabel('Delete')
            ->form(fn (User $record) => static::deletionFormSchema($record))
            ->action(function (User $record, array $data) {
                static::runGuidedDeletion($record, $data);

                return redirect(static::getUrl('index'));
            });
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    protected static function deletionFormSchema(User $record): array
    {
        if ($blocked = static::selfOrLastAdminBlockReason($record)) {
            // Rendered as a read-only notice; the actual hard-stop happens
            // in runGuidedDeletion() too, since a form field alone is not
            // server-side enforcement.
            return [
                Forms\Components\Placeholder::make('blocked')
                    ->label('')
                    ->content($blocked),
            ];
        }

        $service = app(EmployeeDeletionService::class);

        if (! $service->hasDependencies($record)) {
            // Section 5.7: nothing to clean up, so keep the simple
            // confirm-only behavior — no extra choices to make.
            return [];
        }

        $breakdown = $service->dependencyBreakdown($record);
        $requiresReplacement = $service->requiresReplacement($record);

        return [
            Forms\Components\Placeholder::make('breakdown')
                ->label("{$record->name} currently has:")
                ->content(new HtmlString(
                    view('filament.forms.employee-deletion-breakdown', ['breakdown' => $breakdown])->render()
                )),
            Forms\Components\Radio::make('option')
                ->label('What should happen to their records?')
                ->options([
                    'reassign' => 'Keep Companies (Reassign) — Database records stay and become unassigned; their other records are removed.',
                    'delete_everything' => 'Delete Everything (Including Companies) — their Database records, and everything else they own, are removed.',
                ])
                ->required()
                ->default('reassign'),
            Forms\Components\Select::make('replacement_id')
                ->label('Reassign their Call Records to')
                ->helperText('Required — Call Records are permanent history and are never deleted, only handed to someone else. Any record they merely created (but that belongs to someone else) also moves to this person.')
                ->options(fn () => User::query()->whereKeyNot($record->id)->orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->required($requiresReplacement)
                ->visible($requiresReplacement),
        ];
    }

    protected static function runGuidedDeletion(User $record, array $data): void
    {
        if (! auth()->user()?->isAdmin()) {
            // Defense in depth — the action/resource are already admin-only
            // in the UI, but this must not be the only thing stopping a
            // non-admin from deleting an employee.
            throw new Halt;
        }

        if ($blocked = static::selfOrLastAdminBlockReason($record)) {
            Notification::make()->title("Can't delete {$record->name}")->body($blocked)->danger()->send();
            throw new Halt;
        }

        $service = app(EmployeeDeletionService::class);

        try {
            if (! $service->hasDependencies($record)) {
                $service->deleteWithoutDependencies($record);
            } else {
                $replacement = filled($data['replacement_id'] ?? null)
                    ? User::query()->find($data['replacement_id'])
                    : null;

                match ($data['option'] ?? null) {
                    'delete_everything' => $service->deleteEverything($record, $replacement),
                    default => $service->reassignAndDelete($record, $replacement),
                };
            }
        } catch (EmployeeDeletionFailedException $e) {
            Notification::make()->title("Can't delete {$record->name}")->body($e->getMessage())->danger()->send();
            throw new Halt;
        }

        Notification::make()->title("{$record->name} deleted")->success()->send();
    }

    /**
     * Not explicitly requested by any prior change request, but Section 5.8
     * says to preserve any existing "can't delete yourself / can't delete
     * the last admin" protections and not weaken them — since this batch is
     * what first makes User deletion genuinely powerful (Option B cascades
     * across every module), both guards are added here rather than left
     * for a future incident.
     */
    protected static function selfOrLastAdminBlockReason(User $record): ?string
    {
        if (auth()->id() === $record->id) {
            return "You can't delete your own account while logged in as it.";
        }

        if ($record->role === UserRole::Admin && User::query()->where('role', UserRole::Admin)->count() <= 1) {
            return 'This is the last remaining admin — promote another employee to Admin first.';
        }

        return null;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'view' => Pages\ViewUser::route('/{record}'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }
}
