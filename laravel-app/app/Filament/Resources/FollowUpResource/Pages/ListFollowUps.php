<?php

namespace App\Filament\Resources\FollowUpResource\Pages;

use App\Enums\FollowUpStatus;
use App\Filament\Resources\FollowUpResource;
use App\Models\FollowUp;
use App\Support\CsvExporter;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * UX Fixes Batch Issue 3: one page, two tabs, instead of a second
 * resource/nav item. "Pending" is the same actionable queue the page has
 * always shown (FollowUpStatus::Pending); "History" is a UI-only filter —
 * Completed and Cancelled together, not a new status. Both tabs inherit the
 * resource's normal visibleTo() scoping since they only modify the query the
 * resource's getEloquentQuery() already produced.
 */
class ListFollowUps extends ListRecords
{
    protected static string $resource = FollowUpResource::class;

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('Pending')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', FollowUpStatus::Pending))
                ->badge(fn () => FollowUp::query()->visibleTo(auth()->user())->where('status', FollowUpStatus::Pending)->count()),
            'history' => Tab::make('History')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [FollowUpStatus::Completed, FollowUpStatus::Cancelled])),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            // Unlike the Lead/Appointment/Proposal stage exports, this one
            // is intentionally open to every authenticated user (Decision
            // "FollowUp export is the only export gaining employee access
            // in this batch") — scoping is enforced in the query below via
            // the same visibleTo() the table itself uses, independent of
            // whatever the table's current filter state happens to be.
            Actions\Action::make('exportCsv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->form([
                    Forms\Components\Select::make('scope')
                        ->label('Which follow-ups?')
                        ->options([
                            'pending' => 'Pending',
                            'history' => 'History (Completed + Cancelled)',
                            'all' => 'All',
                        ])
                        ->default($this->activeTab === 'history' ? 'history' : 'pending')
                        ->required(),
                ])
                ->action(function (array $data) {
                    $query = FollowUp::query()
                        ->visibleTo(auth()->user())
                        ->with(['prospect', 'responsibleEmployee']);

                    match ($data['scope']) {
                        'pending' => $query->where('status', FollowUpStatus::Pending),
                        'history' => $query->whereIn('status', [FollowUpStatus::Completed, FollowUpStatus::Cancelled]),
                        default => null,
                    };

                    $rows = $query->orderBy('follow_up_at')->get()->map(fn (FollowUp $followUp) => [
                        $followUp->prospect->company_name,
                        $followUp->reason,
                        $followUp->status->getLabel(),
                        $followUp->follow_up_at?->format('Y-m-d H:i'),
                        $followUp->responsibleEmployee->name,
                        $followUp->created_at->format('Y-m-d H:i'),
                    ]);

                    return CsvExporter::stream(
                        "follow-ups-{$data['scope']}-".now()->format('Y-m-d').'.csv',
                        ['Company', 'Reason', 'Status', 'Follow-up At', 'Employee', 'Created At'],
                        $rows,
                    );
                }),
            Actions\CreateAction::make(),
        ];
    }
}
