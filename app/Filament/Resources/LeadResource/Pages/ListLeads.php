<?php

namespace App\Filament\Resources\LeadResource\Pages;

use App\Enums\ExportableResource;
use App\Enums\LeadStatus;
use App\Filament\Resources\LeadResource;
use App\Models\Lead;
use App\Support\Exports\ExportActions;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * Same Pending / History / Lost tab layout as Follow-Ups
 * (FollowUpResource/Pages/ListFollowUps.php): "Pending" is the active queue
 * (not lost, not yet at a normalized-status stop state), "History" is a
 * UI-only filter — terminal-for-progression status and Lost Leads together,
 * not a new status — and "Lost" is the existing `is_lost` flag (see
 * Lead::markLost()). Lost intentionally overlaps with History for the same
 * reason it does on Follow-Ups: it's a narrower view of the same underlying
 * records, not a mutually-exclusive bucket.
 *
 * Phase 3 correction: previously keyed purely on legacy `stage`
 * (LeadStage::isTerminal(), i.e. stage=Validated) — a Lead that reached
 * real normalized readiness for Proposal via the new
 * WorkflowTransitionService-driven workflow (whose legacy `stage` may
 * since have been synced separately, or may not have moved at all) was
 * never reliably reflected here. Migrated to
 * LeadStatus::isTerminalForProgression() (ProposalRequired/
 * NoCurrentProgression) — the same normalized concept PipelinePulse's own
 * Active Lead count now uses, so the two can never diverge again.
 */
class ListLeads extends ListRecords
{
    protected static string $resource = LeadResource::class;

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('Pending')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_lost', false)->whereNotIn('status', self::terminalStatuses()))
                ->badge(fn () => Lead::query()->visibleTo(auth()->user())->where('is_lost', false)->whereNotIn('status', self::terminalStatuses())->count()),
            'history' => Tab::make('History')
                ->modifyQueryUsing(fn (Builder $query) => $query->where(
                    fn (Builder $query) => $query->where('is_lost', true)->orWhereIn('status', self::terminalStatuses())
                )),
            'lost' => Tab::make('Lost')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_lost', true)),
        ];
    }

    /**
     * @return array<string>
     */
    private static function terminalStatuses(): array
    {
        return array_map(
            fn (LeadStatus $status) => $status->value,
            array_filter(LeadStatus::cases(), fn (LeadStatus $status) => $status->isTerminalForProgression())
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            ExportActions::immediate(ExportableResource::Lead),
            ExportActions::request(ExportableResource::Lead),
            Actions\CreateAction::make(),
        ];
    }
}
