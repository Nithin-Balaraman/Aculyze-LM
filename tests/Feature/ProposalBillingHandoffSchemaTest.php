<?php

namespace Tests\Feature;

use App\Enums\ProposalClientResponseType;
use App\Enums\ProposalVersionLifecycle;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalBillingHandoff;
use App\Models\ProposalClientResponse;
use App\Models\ProposalVersion;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4A-3.1: proposal_billing_handoffs schema shape. No recording
 * service exists yet (4A-3.4) — this exercises the constraints directly.
 * Locked Decision 15: the exactly-once backstop is
 * UNIQUE(accepted_response_id), NOT UNIQUE(proposal_id) — a Proposal must
 * remain able to receive a second handoff for a second Accepted event after
 * a future Reopen.
 */
class ProposalBillingHandoffSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function proposalWithSentVersion(User $actor): Proposal
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $actor->id, 'created_by' => $actor->id]);
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $actor->id,
            'created_by' => $actor->id,
            'stage' => 'validated',
            'temperature' => 'hot',
            'notes' => 'Fixture.',
        ]);
        $proposal = Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $actor->id,
            'created_by' => $actor->id,
            'stage' => 'sent',
        ]);

        $version = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'lifecycle_status' => ProposalVersionLifecycle::Sent,
        ]);

        $proposal->forceFill(['current_version_id' => $version->id])->save();

        return $proposal->fresh();
    }

    private function acceptedResponse(Proposal $proposal, User $actor): ProposalClientResponse
    {
        return ProposalClientResponse::create([
            'proposal_id' => $proposal->id,
            'proposal_version_id' => $proposal->current_version_id,
            'response_type' => ProposalClientResponseType::Accepted,
            'recorded_by' => $actor->id,
            'recorded_at' => now(),
            'idempotency_key' => (string) str()->uuid(),
        ]);
    }

    private function handoff(Proposal $proposal, ProposalClientResponse $response, array $overrides = []): ProposalBillingHandoff
    {
        return ProposalBillingHandoff::create(array_merge([
            'proposal_id' => $proposal->id,
            'winning_version_id' => $proposal->current_version_id,
            'accepted_response_id' => $response->id,
            'idempotency_key' => (string) str()->uuid(),
        ], $overrides));
    }

    public function test_accepted_response_id_is_unique(): void
    {
        $actor = User::factory()->create();
        $proposal = $this->proposalWithSentVersion($actor);
        $response = $this->acceptedResponse($proposal, $actor);

        $this->handoff($proposal, $response);

        $this->expectException(QueryException::class);
        $this->handoff($proposal, $response, ['idempotency_key' => (string) str()->uuid()]);
    }

    public function test_proposal_id_is_not_unique_a_second_accepted_event_may_have_its_own_handoff(): void
    {
        $actor = User::factory()->create();
        $proposal = $this->proposalWithSentVersion($actor);

        $firstResponse = $this->acceptedResponse($proposal, $actor);
        $this->handoff($proposal, $firstResponse);

        // Simulate a future Reopen: the first Accepted response is
        // superseded, freeing the operative-Accepted lock for a second one.
        $firstResponse->forceFill(['superseded_at' => now()])->save();
        $secondResponse = $this->acceptedResponse($proposal, $actor);

        $this->handoff($proposal, $secondResponse);

        $this->assertSame(2, ProposalBillingHandoff::query()->where('proposal_id', $proposal->id)->count());
    }

    public function test_status_defaults_to_pending(): void
    {
        $actor = User::factory()->create();
        $proposal = $this->proposalWithSentVersion($actor);
        $response = $this->acceptedResponse($proposal, $actor);

        $handoff = $this->handoff($proposal, $response);

        $this->assertSame(\App\Enums\ProposalBillingHandoffStatus::Pending, $handoff->fresh()->status);
        $this->assertSame(0, $handoff->fresh()->attempts);
    }
}
