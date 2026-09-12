<?php

namespace App\Services;

use App\Enums\ProposalBillingHandoffStatus;
use App\Models\Proposal;
use App\Models\ProposalBillingHandoff;
use App\Models\ProposalClientResponse;
use App\Models\ProposalVersion;
use Illuminate\Support\Str;

/**
 * Phase 4A-3.4, Sections S/T: a small, deliberately PROVIDER-INDEPENDENT
 * service — it creates exactly one Pending `proposal_billing_handoffs` row
 * per Accepted client response and never calls anything external. No HTTP
 * client, no webhook, no API key, no retry job, no cron — this row means
 * only "LM has an accepted/won Proposal ready for future billing
 * integration," nothing more.
 *
 * Always called from inside ProposalClientResponseService::recordAccepted()'s
 * own transaction, after the Accepted response row has already been
 * created — never standalone. `accepted_response_id` is UNIQUE at the DB
 * level (the real exactly-once backstop); the existence check here is
 * defense-in-depth for a direct re-call against the same response, not the
 * primary safety mechanism.
 */
class ProposalBillingHandoffService
{
    public function createPendingHandoff(Proposal $proposal, ProposalVersion $winningVersion, ProposalClientResponse $acceptedResponse): ProposalBillingHandoff
    {
        $existing = ProposalBillingHandoff::query()
            ->where('accepted_response_id', $acceptedResponse->getKey())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return ProposalBillingHandoff::create([
            'proposal_id' => $proposal->getKey(),
            'winning_version_id' => $winningVersion->getKey(),
            'accepted_response_id' => $acceptedResponse->getKey(),
            'idempotency_key' => (string) Str::uuid(),
            'status' => ProposalBillingHandoffStatus::Pending,
            'attempts' => 0,
            'last_attempt_at' => null,
            'last_error' => null,
        ]);
    }
}
