<?php

namespace App\Filament\Resources\ProposalResource\Pages;

use App\Enums\ExportableResource;
use App\Enums\ProposalOutcome;
use App\Filament\Resources\ProposalResource;
use App\Models\Proposal;
use App\Support\Exports\ExportActions;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * Same Pending / History / Lost tab layout as Follow-Ups, Leads, and
 * Appointments (see LeadResource/Pages/ListLeads.php). Proposals already
 * track "Lost" as one of the three `outcome` values (Won/Hold/Lost — see
 * App\Enums\ProposalOutcome) rather than a separate flag, so these tabs
 * reuse that existing field instead of adding a new one: "Pending" is a
 * Proposal with no outcome yet OR on Hold — Hold is not a final decision,
 * just a pause, so it stays in the active queue — "History" is a Proposal
 * with a final outcome (Won or Lost), and "Lost" is outcome = Lost
 * specifically.
 */
class ListProposals extends ListRecords
{
    protected static string $resource = ProposalResource::class;

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('Pending')
                ->modifyQueryUsing(fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereNull('outcome')->orWhere('outcome', ProposalOutcome::Hold)))
                ->badge(fn () => Proposal::query()->visibleTo(auth()->user())->where(fn (Builder $query) => $query->whereNull('outcome')->orWhere('outcome', ProposalOutcome::Hold))->count()),
            'history' => Tab::make('History')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('outcome', [ProposalOutcome::Won, ProposalOutcome::Lost])),
            'lost' => Tab::make('Lost')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('outcome', ProposalOutcome::Lost)),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            ExportActions::immediate(ExportableResource::Proposal),
            ExportActions::request(ExportableResource::Proposal),
            Actions\CreateAction::make(),
        ];
    }
}
