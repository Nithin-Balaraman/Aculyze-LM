<?php

namespace App\Filament\Resources;

use App\Enums\ExportableResource;
use App\Enums\ExportRequestStatus;
use App\Filament\Resources\ExportRequestResource\Pages;
use App\Models\ExportRequest;
use App\Models\User;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Exceptions\Halt;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Import Access + Export Approval batch, Section 2.13/2.14: the single
 * admin-only place every employee export request is reviewed. This *is*
 * the audit trail (Section 2.24) — there is no separate log entity, so
 * the table shows requester, resource, criteria, timestamps, and decider
 * exactly as stored on ExportRequest. Decisions (Approve/Deny) are
 * one-shot: ExportRequest::approve()/deny() refuse to re-decide a request
 * that has already left Pending.
 */
class ExportRequestResource extends Resource
{
    protected static ?string $model = ExportRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationLabel = 'Export Requests';

    protected static ?string $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('requester.name')
                    ->label('Employee')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('resource')
                    ->label('Resource')
                    ->badge(),
                Tables\Columns\TextColumn::make('criteria')
                    ->label('Requested Criteria')
                    ->state(fn (ExportRequest $record) => $record->resource->exporter()->summarizeCriteria($record->filters)),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Requested At')
                    ->dateTime('d M Y, h:i A')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (ExportRequest $record) => $record->effectiveStatusLabel())
                    ->color(fn (ExportRequest $record) => $record->effectiveStatusColor()),
                Tables\Columns\TextColumn::make('decider.name')
                    ->label('Decided By')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('decided_at')
                    ->label('Decided At')
                    ->dateTime('d M Y, h:i A')
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(ExportRequestStatus::class),
                Tables\Filters\SelectFilter::make('resource')
                    ->options(ExportableResource::class),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (ExportRequest $record) => $record->status === ExportRequestStatus::Pending && auth()->user()->can('decide', $record))
                    ->action(fn (ExportRequest $record) => static::decide($record, fn (User $admin) => $record->approve($admin))),
                Tables\Actions\Action::make('deny')
                    ->label('Deny')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (ExportRequest $record) => $record->status === ExportRequestStatus::Pending && auth()->user()->can('decide', $record))
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Reason (optional)')
                            ->rows(3),
                    ])
                    ->action(fn (ExportRequest $record, array $data) => static::decide($record, fn (User $admin) => $record->deny($admin, $data['reason'] ?? null))),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No export requests yet.')
            ->emptyStateDescription('When an employee requests a CSV export, it shows up here for review.')
            ->emptyStateIcon('heroicon-o-inbox-arrow-down');
    }

    /**
     * Shared by both row actions: re-checks authorization inside the
     * closure (a hidden button is never the only guard) and wraps the
     * decision in a transaction, then turns the model's own
     * "already decided" guard into a user-facing notice instead of a
     * fatal error if two admins race to decide the same request.
     */
    private static function decide(ExportRequest $record, \Closure $decision): void
    {
        if (! (auth()->user()?->can('decide', $record) ?? false)) {
            throw new Halt;
        }

        try {
            DB::transaction(fn () => $decision(auth()->user()));
        } catch (\LogicException $e) {
            Notification::make()
                ->title("Can't decide this request")
                ->body($e->getMessage())
                ->danger()
                ->send();

            throw new Halt;
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExportRequests::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = static::getModel()::query()->where('status', ExportRequestStatus::Pending)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
