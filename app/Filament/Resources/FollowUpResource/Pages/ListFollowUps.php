<?php

namespace App\Filament\Resources\FollowUpResource\Pages;

use App\Enums\ExportableResource;
use App\Enums\FollowUpStatus;
use App\Filament\Resources\FollowUpResource;
use App\Models\FollowUp;
use App\Support\Exports\ExportActions;
use Filament\Actions;
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
 *
 * Import Access + Export Approval batch, Section 2.5: the immediate CSV
 * export that used to be open to every authenticated user is admin-only
 * again — employees now go through Request Export like every other
 * resource, via the shared ExportActions/FollowUpExporter.
 *
 * Employee Prospect Visibility / Export Dedup / Follow-Up Lost batch,
 * Section 3: a third "Lost" tab — Cancelled Follow-Ups only, using the
 * existing FollowUpStatus::Cancelled value (no new status/column). Lost
 * intentionally overlaps with History: a Cancelled Follow-Up is expected
 * to appear in both, since "Lost" is just a narrower view of the same
 * underlying Cancelled records, not a mutually-exclusive bucket. No badge
 * is added here since History — the other pre-existing overlapping tab —
 * doesn't have one either; adding one only to Lost would be inconsistent.
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
            'lost' => Tab::make('Lost')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', FollowUpStatus::Cancelled)),
        ];
    }

    /**
     * Phase 2 item #3: History/Lost group by company, Pending stays flat
     * (see FollowUpResource::table()'s ->defaultGroup()). $tableGrouping
     * (from Filament's CanGroupRecords trait) is a persistent Livewire
     * property, not re-derived from scratch on every request, so switching
     * tabs needs an explicit push here rather than relying solely on
     * ->defaultGroup()'s closure re-evaluating — otherwise grouping picked
     * up on History could still be showing after clicking back to Pending.
     */
    public function updatedActiveTab(): void
    {
        $this->tableGrouping = in_array($this->activeTab, ['history', 'lost'], true)
            ? FollowUpResource::GROUP_BY_COMPANY
            : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            ExportActions::immediate(ExportableResource::FollowUp),
            ExportActions::request(ExportableResource::FollowUp),
            Actions\CreateAction::make(),
        ];
    }
}
