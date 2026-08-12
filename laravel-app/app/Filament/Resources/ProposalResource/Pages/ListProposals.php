<?php

namespace App\Filament\Resources\ProposalResource\Pages;

use App\Enums\ExportableResource;
use App\Filament\Resources\ProposalResource;
use App\Support\Exports\ExportActions;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProposals extends ListRecords
{
    protected static string $resource = ProposalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ExportActions::immediate(ExportableResource::Proposal),
            ExportActions::request(ExportableResource::Proposal),
            Actions\CreateAction::make(),
        ];
    }
}
