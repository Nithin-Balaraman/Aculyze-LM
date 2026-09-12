<?php

namespace Tests\Feature;

use App\Enums\ProposalClientResponseNextAction;
use App\Enums\ProposalVersionLifecycle;
use App\Enums\UserRole;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalClientResponse;
use App\Models\ProposalVersion;
use App\Models\Prospect;
use App\Models\User;
use App\Services\ProposalClientResponseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * Phase 4A-3.4, Section N: Other — exactly two next actions (Await Further
 * Contact, Create Follow-Up), each with its own required inputs and side
 * effects.
 */
class ProposalClientResponseOtherTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{employee: User, manager: User, seniorManager: User} */
    private function hierarchy(): array
    {
        $seniorManager = User::factory()->admin()->create();
        $manager = User::factory()->create(['role' => UserRole::Manager, 'manager_id' => $seniorManager->id]);
        $employee = User::factory()->create(['role' => UserRole::Employee, 'manager_id' => $manager->id]);

        return compact('employee', 'manager', 'seniorManager');
    }

    /** @return array{0: Proposal, 1: ProposalVersion} */
    private function sentProposal(User $employee): array
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $lead = Lead::create([
            'prospect_id' => $prospect->id, 'assigned_to' => $employee->id, 'created_by' => $employee->id,
            'stage' => 'validated', 'temperature' => 'hot', 'notes' => 'Fixture.',
        ]);
        $proposal = Proposal::create([
            'lead_id' => $lead->id, 'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id, 'created_by' => $employee->id, 'stage' => 'sent',
        ]);
        $version = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'lifecycle_status' => ProposalVersionLifecycle::Sent,
            'customer_name_snapshot' => 'Acme Corp',
            'grand_total' => 1000,
        ]);
        $proposal->forceFill(['current_version_id' => $version->id])->save();

        return [$proposal->fresh(), $version->fresh(['proposal'])];
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    // --- Await Further Contact ---

    public function test_await_notes_are_mandatory(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [, $version] = $this->sentProposal($employee);

        $this->expectException(LogicException::class);

        app(ProposalClientResponseService::class)->recordOtherAwaitFurtherContact($version, $employee, '', $this->key());
    }

    public function test_await_creates_no_downstream_record(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [, $version] = $this->sentProposal($employee);

        app(ProposalClientResponseService::class)->recordOtherAwaitFurtherContact($version, $employee, 'Will follow up next quarter.', $this->key());

        $this->assertSame(0, FollowUp::query()->count());
        $this->assertSame(1, ProposalVersion::query()->count());
    }

    public function test_await_next_action_is_recorded(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [, $version] = $this->sentProposal($employee);

        $response = app(ProposalClientResponseService::class)->recordOtherAwaitFurtherContact($version, $employee, 'Will follow up next quarter.', $this->key());

        $this->assertSame(ProposalClientResponseNextAction::AwaitFurtherContact, $response->next_action);
    }

    public function test_await_does_not_change_outcome_or_stage(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);
        $originalStage = $proposal->fresh()->stage;

        app(ProposalClientResponseService::class)->recordOtherAwaitFurtherContact($version, $employee, 'Will follow up next quarter.', $this->key());

        $fresh = $proposal->fresh();
        $this->assertNull($fresh->outcome);
        $this->assertSame($originalStage, $fresh->stage);
    }

    public function test_await_does_not_update_last_client_activity_at(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);

        app(ProposalClientResponseService::class)->recordOtherAwaitFurtherContact($version, $employee, 'Will follow up next quarter.', $this->key());

        $this->assertNull($proposal->fresh()->last_client_activity_at);
    }

    // --- Create Follow-Up ---

    public function test_create_follow_up_requires_follow_up_at(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [, $version] = $this->sentProposal($employee);

        $this->expectException(\TypeError::class);

        app(ProposalClientResponseService::class)->recordOtherCreateFollowUp($version, $employee, null, 'Reason', null, null, null, $this->key());
    }

    public function test_create_follow_up_requires_reason(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [, $version] = $this->sentProposal($employee);

        $this->expectException(LogicException::class);

        app(ProposalClientResponseService::class)->recordOtherCreateFollowUp($version, $employee, now()->addDays(3), '', null, null, null, $this->key());
    }

    public function test_create_follow_up_creates_exactly_one_follow_up(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [, $version] = $this->sentProposal($employee);

        app(ProposalClientResponseService::class)->recordOtherCreateFollowUp($version, $employee, now()->addDays(3), 'Wants a callback', null, null, null, $this->key());

        $this->assertSame(1, FollowUp::query()->count());
    }

    public function test_create_follow_up_assigned_to_proposal_employee(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);

        app(ProposalClientResponseService::class)->recordOtherCreateFollowUp($version, $manager, now()->addDays(3), 'Wants a callback', null, null, null, $this->key());

        $followUp = FollowUp::query()->sole();
        $this->assertSame($employee->id, $followUp->user_id);
        $this->assertSame('proposal', $followUp->origin_type);
    }

    public function test_create_follow_up_updates_last_client_activity_at(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);

        app(ProposalClientResponseService::class)->recordOtherCreateFollowUp($version, $employee, now()->addDays(3), 'Wants a callback', null, null, null, $this->key());

        $this->assertNotNull($proposal->fresh()->last_client_activity_at);
    }

    public function test_create_follow_up_does_not_change_outcome_or_stage(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);
        $originalStage = $proposal->fresh()->stage;

        app(ProposalClientResponseService::class)->recordOtherCreateFollowUp($version, $employee, now()->addDays(3), 'Wants a callback', null, null, null, $this->key());

        $fresh = $proposal->fresh();
        $this->assertNull($fresh->outcome);
        $this->assertSame($originalStage, $fresh->stage);
    }

    public function test_create_follow_up_replay_does_not_duplicate(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [, $version] = $this->sentProposal($employee);
        $key = $this->key();
        $followUpAt = now()->addDays(3);

        app(ProposalClientResponseService::class)->recordOtherCreateFollowUp($version, $employee, $followUpAt, 'Wants a callback', null, null, null, $key);
        app(ProposalClientResponseService::class)->recordOtherCreateFollowUp($version->fresh(), $employee, $followUpAt, 'Wants a callback', null, null, null, $key);

        $this->assertSame(1, FollowUp::query()->count());
        $this->assertSame(1, ProposalClientResponse::query()->count());
    }
}
