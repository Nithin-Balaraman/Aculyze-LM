<?php

namespace App\Filament\Resources\ProposalResource\Pages;

use App\Enums\ProposalStage;
use App\Filament\Resources\ProposalResource;
use App\Models\Lead;
use App\Services\ProposalCreationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

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

        return $data;
    }

    /**
     * Phase 4A-2.4: the narrowest Filament hook that actually performs the
     * insert — overridden (instead of enabling panel-wide DB transactions)
     * so this page delegates to the centralized ProposalCreationService,
     * which atomically creates the Proposal AND its V1 Draft
     * ProposalVersion (locked Decision 12) rather than the bare Proposal
     * Filament's own default handleRecordCreation() would otherwise leave
     * behind. prospect_id is derived here from the submitted lead_id, same
     * as mutateFormDataBeforeCreate() used to derive it, since the service
     * itself derives prospect_id from the Lead rather than accepting it.
     *
     * Phase 4A-3.5 cutover: stage and outcome are now exclusively
     * service-owned lifecycle state — ProposalSendService's first send and
     * ProposalClientResponseService's Accepted/Revision Requested/Rejected
     * transitions. A brand-new Proposal may only ever start at its neutral
     * default (Proposal Being Prepared, no outcome yet); it can no longer be
     * created directly into a later stage or a final outcome, regardless of
     * what a caller supplies. `value` and `sent_at` (Phase 4A-3.6: the
     * latter now legacy/read-only) are likewise no longer form fields, so
     * $data never carries them — omitted here rather than passed through
     * as always-null keys.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $lead = Lead::query()->findOrFail($data['lead_id']);

        return app(ProposalCreationService::class)->createForLead($lead, [
            'assigned_to' => $data['assigned_to'],
            'created_by' => $data['created_by'],
            'stage' => ProposalStage::BeingPrepared->value,
            'notes' => $data['notes'] ?? null,
            'attachment_paths' => $data['attachment_paths'] ?? [],
            'attachment_names' => $data['attachment_names'] ?? [],
        ]);
    }

    // Return to the list, not the new record's view/edit page — same
    // destination "Cancel" already goes to (Filament's default here is
    // view-then-edit-then-index).
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
