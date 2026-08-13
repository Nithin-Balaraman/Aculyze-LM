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
 * Proposal with no outcome decided yet, "History" is any Proposal with a
 * final outcome recorded, and "Lost" is outcome = Lost specifically.
 */
class ListProposals extends ListRecords
{
    protected static string $resource = ProposalResource::class;

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('Pending')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('outcome'))
                ->badge(fn () => Proposal::query()->visibleTo(auth()->user())->whereNull('outcome')->count()),
            'history' => Tab::make('History')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('outcome')),
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
