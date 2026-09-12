<?php

namespace Tests\Feature;

use App\Enums\ProposalOutcome;
use App\Enums\ProposalStage;
use App\Enums\ProposalVersionLifecycle;
use App\Enums\UserRole;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\Prospect;
use App\Models\User;
use App\Services\ProposalClientResponseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4A-3.4, Sections K/L: More Time / Decision Pending — Hold outcome,
 * stage stays Sent, exactly one Follow-Up, and the stale-timing rule
 * (locked Decision 18): stage_changed_at moves ONLY on a genuine stage
 * change, never on an outcome-only Hold write; last_client_activity_at is
 * the meaningful stale reference here.
 */
class ProposalClientResponseMoreTimeTest extends TestCase
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

    public function test_response_row_created(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [, $version] = $this->sentProposal($employee);

        $response = app(ProposalClientResponseService::class)->recordMoreTime(
            $version, $employee, now()->addDays(5), 'Budget review pending', null, null, null, $this->key()
        );

        $this->assertNotNull($response->getKey());
    }

    public function test_outcome_becomes_hold(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);

        app(ProposalClientResponseService::class)->recordMoreTime($version, $employee, now()->addDays(5), 'Budget review', null, null, null, $this->key());

        $this->assertSame(ProposalOutcome::Hold, $proposal->fresh()->outcome);
    }

    public function test_stage_remains_sent(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);

        app(ProposalClientResponseService::class)->recordMoreTime($version, $employee, now()->addDays(5), 'Budget review', null, null, null, $this->key());

        $this->assertSame(ProposalStage::Sent, $proposal->fresh()->stage);
    }

    public function test_exactly_one_follow_up_created(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [, $version] = $this->sentProposal($employee);

        app(ProposalClientResponseService::class)->recordMoreTime($version, $employee, now()->addDays(5), 'Budget review', null, null, null, $this->key());

        $this->assertSame(1, FollowUp::query()->count());
    }

    public function test_follow_up_assigned_to_proposal_employee(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);

        // Recorded BY the manager, but assigned to the Proposal's own employee.
        app(ProposalClientResponseService::class)->recordMoreTime($version, $manager, now()->addDays(5), 'Budget review', null, null, null, $this->key());

        $followUp = FollowUp::query()->sole();
        $this->assertSame($employee->id, $followUp->user_id);
    }

    public function test_origin_type_is_proposal(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);

        app(ProposalClientResponseService::class)->recordMoreTime($version, $employee, now()->addDays(5), 'Budget review', null, null, null, $this->key());

        $followUp = FollowUp::query()->sole();
        $this->assertSame('proposal', $followUp->origin_type);
        $this->assertSame($proposal->getKey(), $followUp->origin_id);
    }

    public function test_last_client_activity_at_updated(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);
        $this->assertNull($proposal->fresh()->last_client_activity_at);

        app(ProposalClientResponseService::class)->recordMoreTime($version, $employee, now()->addDays(5), 'Budget review', null, null, null, $this->key());

        $this->assertNotNull($proposal->fresh()->last_client_activity_at);
    }

    public function test_stage_changed_at_unchanged_on_first_more_time(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);
        $original = $proposal->fresh()->stage_changed_at;

        Carbon::setTestNow(now()->addHour());
        app(ProposalClientResponseService::class)->recordMoreTime($version, $employee, now()->addDays(5), 'Budget review', null, null, null, $this->key());
        Carbon::setTestNow();

        $this->assertTrue($original->equalTo($proposal->fresh()->stage_changed_at));
    }

    public function test_stage_changed_at_unchanged_on_repeated_more_time(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);
        $original = $proposal->fresh()->stage_changed_at;

        app(ProposalClientResponseService::class)->recordMoreTime($version, $employee, now()->addDays(5), 'First delay', null, null, null, $this->key());

        Carbon::setTestNow(now()->addHours(2));
        app(ProposalClientResponseService::class)->recordMoreTime($version->fresh(), $employee, now()->addDays(10), 'Second delay', null, null, null, $this->key());
        Carbon::setTestNow();

        $this->assertTrue($original->equalTo($proposal->fresh()->stage_changed_at));
    }

    public function test_replay_does_not_duplicate_follow_up(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [, $version] = $this->sentProposal($employee);
        $key = $this->key();
        $followUpAt = now()->addDays(5);

        app(ProposalClientResponseService::class)->recordMoreTime($version, $employee, $followUpAt, 'Budget review', null, null, null, $key);
        app(ProposalClientResponseService::class)->recordMoreTime($version->fresh(), $employee, $followUpAt, 'Budget review', null, null, null, $key);

        $this->assertSame(1, FollowUp::query()->count());
        $this->assertSame(1, \App\Models\ProposalClientResponse::query()->count());
    }

    // --- Stale timing alignment (items 77-79, 82) ---

    public function test_stale_query_and_php_is_stale_remain_aligned_after_more_time(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);

        // Push the Proposal's stage_changed_at far into the past so it
        // would be stale on stage_changed_at alone...
        $proposal->forceFill(['stage_changed_at' => now()->subDays(30)])->saveQuietly();

        // ...then a fresh More Time response updates last_client_activity_at
        // to NOW, which must push the effective reference date later and
        // make the Proposal NOT stale — both the PHP isStale() and the
        // scopeStale() SQL query must agree.
        app(ProposalClientResponseService::class)->recordMoreTime($version->fresh(), $employee, now()->addDays(5), 'Budget review', null, null, null, $this->key());

        $fresh = $proposal->fresh();
        $this->assertFalse($fresh->isStale());
        $this->assertFalse(Proposal::query()->stale()->whereKey($fresh->getKey())->exists());
    }

    /**
     * Phase 4A-3.4, Section V: a Follow-Up that is the historical result of
     * a client response cannot be destructively deleted — this would break
     * permanent response history. `deletionBlockers()` surfaces the
     * friendly guard message instead of a raw FK error.
     */
    public function test_follow_up_referenced_by_a_client_response_cannot_be_destructively_deleted(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [, $version] = $this->sentProposal($employee);

        $response = app(ProposalClientResponseService::class)->recordMoreTime(
            $version, $employee, now()->addDays(5), 'Budget review', null, null, null, $this->key()
        );
        $followUp = FollowUp::find($response->follow_up_id);

        $blockers = $followUp->deletionBlockers();

        $this->assertSame(1, $blockers['Proposal client response']);
    }
}
