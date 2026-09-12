<?php

namespace Tests\Feature;

use App\Enums\ProposalOutcome;
use App\Enums\ProposalStage;
use App\Enums\ProposalVersionLifecycle;
use App\Enums\UserRole;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalBillingHandoff;
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
 * Phase 4A-3.4, Sections F/G/S: Accepted -> Won atomically, exact
 * winning_version_id/value, exactly-one Pending billing handoff, and the
 * DB-backed exactly-once/idempotency guarantees.
 */
class ProposalClientResponseAcceptedTest extends TestCase
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
    private function sentProposal(User $employee, bool $legacy = false, ?float $grandTotal = 12345.67): array
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
            'customer_name_snapshot' => $legacy ? null : 'Acme Corp',
            'grand_total' => $grandTotal,
            'is_legacy_backfill' => $legacy,
        ]);
        $proposal->forceFill(['current_version_id' => $version->id])->save();

        return [$proposal->fresh(), $version->fresh(['proposal'])];
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    public function test_accepted_creates_a_response_row(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [, $version] = $this->sentProposal($employee);

        $response = app(ProposalClientResponseService::class)->recordAccepted($version, $employee, null, $this->key());

        $this->assertNotNull($response->getKey());
        $this->assertSame(1, ProposalClientResponse::query()->count());
    }

    public function test_exact_response_version_becomes_winning_version_id(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);

        app(ProposalClientResponseService::class)->recordAccepted($version, $employee, null, $this->key());

        $this->assertSame($version->getKey(), $proposal->fresh()->winning_version_id);
    }

    public function test_outcome_becomes_won(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);

        app(ProposalClientResponseService::class)->recordAccepted($version, $employee, null, $this->key());

        $this->assertSame(ProposalOutcome::Won, $proposal->fresh()->outcome);
    }

    public function test_stage_becomes_customer_accepted(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);

        app(ProposalClientResponseService::class)->recordAccepted($version, $employee, null, $this->key());

        $this->assertSame(ProposalStage::CustomerAccepted, $proposal->fresh()->stage);
    }

    public function test_value_equals_exact_version_grand_total_never_recalculated(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee, grandTotal: 98765.43);

        app(ProposalClientResponseService::class)->recordAccepted($version, $employee, null, $this->key());

        $this->assertSame('98765.43', $proposal->fresh()->value);
    }

    public function test_pending_billing_handoff_created(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [, $version] = $this->sentProposal($employee);

        app(ProposalClientResponseService::class)->recordAccepted($version, $employee, null, $this->key());

        $this->assertSame(1, ProposalBillingHandoff::query()->count());
    }

    public function test_handoff_references_accepted_response_id(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [, $version] = $this->sentProposal($employee);

        $response = app(ProposalClientResponseService::class)->recordAccepted($version, $employee, null, $this->key());
        $handoff = ProposalBillingHandoff::query()->first();

        $this->assertSame($response->getKey(), $handoff->accepted_response_id);
    }

    public function test_accepted_response_id_uniqueness_enforced_at_db_level(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [, $versionA] = $this->sentProposal($employee);
        [, $versionB] = $this->sentProposal($employee);

        $responseA = app(ProposalClientResponseService::class)->recordAccepted($versionA, $employee, null, $this->key());
        $handoffA = ProposalBillingHandoff::query()->where('accepted_response_id', $responseA->getKey())->sole();

        $this->expectException(\Illuminate\Database\QueryException::class);

        ProposalBillingHandoff::create([
            'proposal_id' => $handoffA->proposal_id,
            'winning_version_id' => $handoffA->winning_version_id,
            'accepted_response_id' => $responseA->getKey(),
            'idempotency_key' => (string) Str::uuid(),
            'status' => 'pending',
            'attempts' => 0,
        ]);
    }

    public function test_no_external_api_call_is_made(): void
    {
        // Structural proof: no HTTP client / provider adapter is imported
        // or referenced anywhere in the service or its billing handoff
        // helper — the only observable effect is local DB rows.
        $serviceSource = file_get_contents(app_path('Services/ProposalClientResponseService.php'));
        $handoffSource = file_get_contents(app_path('Services/ProposalBillingHandoffService.php'));

        foreach ([$serviceSource, $handoffSource] as $source) {
            $this->assertStringNotContainsString('Http::', $source);
            $this->assertStringNotContainsString('GuzzleHttp', $source);
            $this->assertStringNotContainsString('curl_', $source);
        }
    }

    public function test_no_follow_up_or_draft_created(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [, $version] = $this->sentProposal($employee);

        $response = app(ProposalClientResponseService::class)->recordAccepted($version, $employee, null, $this->key());

        $this->assertNull($response->follow_up_id);
        $this->assertNull($response->resulting_draft_version_id);
        $this->assertSame(0, \App\Models\FollowUp::query()->count());
        $this->assertSame(1, ProposalVersion::query()->count());
    }

    public function test_legacy_sent_version_can_be_accepted(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee, legacy: true, grandTotal: 4000);

        $response = app(ProposalClientResponseService::class)->recordAccepted($version, $employee, null, $this->key());

        $this->assertSame(ProposalOutcome::Won, $proposal->fresh()->outcome);
        $this->assertSame($version->getKey(), $proposal->fresh()->winning_version_id);
        $this->assertSame('4000.00', $proposal->fresh()->value);
    }

    // --- Idempotency ---

    public function test_same_key_same_payload_reuses_response(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [, $version] = $this->sentProposal($employee);
        $key = $this->key();

        $first = app(ProposalClientResponseService::class)->recordAccepted($version, $employee, 'Great!', $key);
        $second = app(ProposalClientResponseService::class)->recordAccepted($version->fresh(), $employee, 'Great!', $key);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, ProposalClientResponse::query()->count());
    }

    public function test_no_duplicate_handoff_on_replay(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [, $version] = $this->sentProposal($employee);
        $key = $this->key();

        app(ProposalClientResponseService::class)->recordAccepted($version, $employee, null, $key);
        app(ProposalClientResponseService::class)->recordAccepted($version->fresh(), $employee, null, $key);

        $this->assertSame(1, ProposalBillingHandoff::query()->count());
    }

    public function test_same_key_different_payload_rejected(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [, $version] = $this->sentProposal($employee);
        $key = $this->key();

        app(ProposalClientResponseService::class)->recordAccepted($version, $employee, 'First note', $key);

        $this->expectException(LogicException::class);

        app(ProposalClientResponseService::class)->recordAccepted($version->fresh(), $employee, 'Different note', $key);
    }

    public function test_operative_accepted_lock_prevents_a_second_concurrent_operative_accepted(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);
        $response = app(ProposalClientResponseService::class)->recordAccepted($version, $employee, null, $this->key());

        // Direct duplicate-insert race simulation against the DB backstop
        // itself — a second operative Accepted row for the SAME Proposal
        // must be rejected at the database level regardless of any
        // application-level check.
        $this->expectException(\Illuminate\Database\QueryException::class);

        ProposalClientResponse::create([
            'proposal_id' => $proposal->getKey(),
            'proposal_version_id' => $version->getKey(),
            'response_type' => 'accepted',
            'recorded_by' => $employee->id,
            'recorded_at' => now(),
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }
}
