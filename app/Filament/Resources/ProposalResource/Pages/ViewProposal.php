<?php

namespace App\Filament\Resources\ProposalResource\Pages;

use App\Filament\Resources\ProposalResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewProposal extends ViewRecord
{
    protected static string $resource = ProposalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
            ProposalResource::downloadAttachmentAction(),
        ];
    }

    // Surfaces the Proposal's own database ID next to the page heading so
    // it's visible before ever downloading an attachment, not just after.
    public function getSubheading(): ?string
    {
        return ProposalResource::recordSubheading($this->getRecord());
    }
}
