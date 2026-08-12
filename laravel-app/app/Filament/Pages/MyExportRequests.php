<?php

namespace App\Filament\Pages;

use App\Models\ExportRequest;
use Filament\Pages\Page;
use Filament\Support\Exceptions\Halt;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Import Access + Export Approval batch, Section 2.15-2.18: every
 * employee's own export-request history — always scoped to
 * auth()->id(), never org-wide (that view is
 * App\Filament\Resources\ExportRequestResource, admin-only). The CSV
 * itself is only ever generated here, on click, from the record's stored
 * criteria plus the current employee's scoped query — never at request
 * time and never persisted (see App\Support\Exports\ResourceExporter).
 */
class MyExportRequests extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-document-arrow-down';

    protected static ?string $navigationLabel = 'My Export Requests';

    protected static ?string $title = 'My Export Requests';

    protected static string $view = 'filament.pages.my-export-requests';

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => ExportRequest::query()->where('user_id', auth()->id()))
            ->columns([
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
                Tables\Columns\TextColumn::make('decided_at')
                    ->label('Decision Date')
                    ->dateTime('d M Y, h:i A')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('denial_reason')
                    ->label('Denial Reason')
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->actions([
                Tables\Actions\Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->visible(fn (ExportRequest $record) => auth()->user()->can('download', $record))
                    ->action(function (ExportRequest $record) {
                        if (! auth()->user()->can('download', $record)) {
                            throw new Halt;
                        }

                        $response = $record->resource->exporter()->stream(auth()->user(), $record->filters);

                        $record->forceFill(['downloaded_at' => now()])->save();

                        return $response;
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading("You haven't requested any exports yet.")
            ->emptyStateDescription('Use "Request Export" on Leads, Appointments, Proposals, or Follow-Ups to get started.')
            ->emptyStateIcon('heroicon-o-document-arrow-down');
    }
}
