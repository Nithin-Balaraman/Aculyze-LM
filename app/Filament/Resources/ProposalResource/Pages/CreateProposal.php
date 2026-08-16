<?php

namespace App\Filament\Resources\ProposalResource\Pages;

use App\Filament\Resources\ProposalResource;
use App\Models\Lead;
use Filament\Resources\Pages\CreateRecord;

class CreateProposal extends CreateRecord
{
    protected static string $resource = ProposalResource::class;

    /**
     * Support being deep-linked from a validated Lead's "Create Proposal"
     * row action (?lead_id=…) so the Lead doesn't have to be re-selected.
     */
    public function mount(): void
    {
        parent::mount();

        if ($leadId = request()->query('lead_id')) {
            $this->data['lead_id'] = (int) $leadId;
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['prospect_id'] = Lead::query()->findOrFail($data['lead_id'])->prospect_id;

        return $data;
    }

    // Return to the list, not the new record's view/edit page — same
    // destination "Cancel" already goes to (Filament's default here is
    // view-then-edit-then-index).
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
