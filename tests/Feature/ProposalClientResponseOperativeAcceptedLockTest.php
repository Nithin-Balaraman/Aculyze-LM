<?php

namespace Tests\Feature;

use App\Enums\ProposalClientResponseType;
use App\Enums\ProposalVersionLifecycle;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalClientResponse;
use App\Models\ProposalVersion;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4A-3.1: proposal_client_responses.operative_accepted_lock_key — the
 * STORED generated column enforcing "at most one OPERATIVE Accepted
 * response per Proposal at a time" (locked Decision 13), deliberately NOT
 * "one Accepted ever": a superseded Accepted row frees the lock. No
 * recording service exists yet (4A-3.4) — this exercises the constraint
 * directly.
 */
class ProposalClientResponseOperativeAcceptedLockTest extends TestCase
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

    private function response(Proposal $proposal, User $actor, array $overrides = []): ProposalClientResponse
    {
        return ProposalClientResponse::create(array_merge([
            'proposal_id' => $proposal->id,
            'proposal_version_id' => $proposal->current_version_id,
            'response_type' => ProposalClientResponseType::Accepted,
            'recorded_by' => $actor->id,
            'recorded_at' => now(),
            'idempotency_key' => (string) str()->uuid(),
        ], $overrides));
    }

    public function test_one_operative_accepted_per_proposal(): void
    {
        $actor = User::factory()->create();
        $proposal = $this->proposalWithSentVersion($actor);

        $this->response($proposal, $actor);

        $this->expectException(QueryException::class);
        $this->response($proposal, $actor);
    }

    public function test_superseded_accepted_frees_the_lock(): void
    {
        $actor = User::factory()->create();
        $proposal = $this->proposalWithSentVersion($actor);

        $first = $this->response($proposal, $actor);
        $second = $this->response($proposal, $actor, ['response_type' => ProposalClientResponseType::Rejected, 'reason' => 'Budget cut.']);
        // $second exists only to prove non-Accepted rows never touch the lock.
        $this->assertNotNull($second->fresh());

        $first->forceFill(['superseded_at' => now()])->save();

        $reopened = $this->response($proposal, $actor);

        $this->assertSame(2, ProposalClientResponse::query()->where('proposal_id', $proposal->id)->where('response_type', ProposalClientResponseType::Accepted)->count());
        $this->assertNull($reopened->fresh()->superseded_at);
    }

    public function test_non_accepted_responses_never_collide_with_each_other(): void
    {
        $actor = User::factory()->create();
        $proposal = $this->proposalWithSentVersion($actor);

        $this->response($proposal, $actor, ['response_type' => ProposalClientResponseType::MoreTime, 'idempotency_key' => (string) str()->uuid()]);
        $this->response($proposal, $actor, ['response_type' => ProposalClientResponseType::MoreTime, 'idempotency_key' => (string) str()->uuid()]);

        $this->assertSame(2, ProposalClientResponse::query()->where('proposal_id', $proposal->id)->count());
    }

    public function test_a_different_proposal_may_have_its_own_operative_accepted(): void
    {
        $actor = User::factory()->create();
        $proposalOne = $this->proposalWithSentVersion($actor);
        $proposalTwo = $this->proposalWithSentVersion($actor);

        $this->response($proposalOne, $actor);
        $this->response($proposalTwo, $actor);

        $this->assertSame(1, ProposalClientResponse::query()->where('proposal_id', $proposalOne->id)->count());
        $this->assertSame(1, ProposalClientResponse::query()->where('proposal_id', $proposalTwo->id)->count());
    }
}
