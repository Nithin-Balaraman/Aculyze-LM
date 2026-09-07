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
            // Phase 4A-2.5: the only entry point into the new commercial
            // ProposalVersion workflow — no separate navigation item exists
            // for it (locked product decision: ProposalVersion is
            // contextual under Proposal, never a global Resource).
            Actions\Action::make('commercialVersion')
                ->label('Commercial Version')
                ->icon('heroicon-o-document-currency-rupee')
                ->url(fn () => ProposalResource::getUrl('commercial', ['record' => $this->getRecord()])),
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
