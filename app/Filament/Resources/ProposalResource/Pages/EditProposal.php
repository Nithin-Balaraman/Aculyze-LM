<?php

namespace App\Filament\Resources\ProposalResource\Pages;

use App\Filament\Resources\ProposalResource;
use App\Models\Proposal;
use App\Support\DeletionGuard;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Livewire\Attributes\Url;

class EditProposal extends EditRecord
{
    protected static string $resource = ProposalResource::class;

    // Populated from ?activeTab=... on the URL the row's Edit link built
    // (see ProposalResource::table()) — same #[Url] mechanism ListRecords
    // itself uses for its own activeTab. Null for a direct/bookmarked
    // edit URL with nothing to carry.
    #[Url]
    public ?string $activeTab = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            ProposalResource::downloadAttachmentAction(),
            // Same server-side blocker the list row's Delete uses (audit
            // fix pass 1, F1) — mirrors EditLead/EditFollowUp/
            // EditCallRecord, so no Proposal with commercial Version
            // history can be deleted from this page either.
            Actions\DeleteAction::make()
                ->before(fn (Proposal $record) => DeletionGuard::guardRecord($record, 'proposal')),
        ];
    }

    // Surfaces the Proposal's own database ID next to the page heading so
    // it's visible before ever downloading an attachment, not just after.
    public function getSubheading(): ?string
    {
        return ProposalResource::recordSubheading($this->getRecord());
    }

    // Return to the list instead of Filament's default (stay on this same
    // Edit page) — same destination "Cancel" already goes to (mirrors the
    // Create*.php redirect fix). Reattaches $activeTab so the user lands
    // back on History/Lost instead of always Pending; falls back to
    // today's plain-index behavior when it's null.
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index', array_filter([
            'activeTab' => $this->activeTab,
        ]));
    }
}
