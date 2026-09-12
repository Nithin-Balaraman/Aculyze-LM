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
 * Phase 4A-3.4, Section M: Rejected / No Further Progression -> Lost,
 * atomically, terminal.
 */
class ProposalClientResponseRejectedTest extends TestCase
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

    public function test_reason_is_mandatory(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [, $version] = $this->sentProposal($employee);

        $this->expectException(LogicException::class);

        app(ProposalClientResponseService::class)->recordRejected($version, $employee, '', null, $this->key());
    }

    public function test_response_row_created(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [, $version] = $this->sentProposal($employee);

        $response = app(ProposalClientResponseService::class)->recordRejected($version, $employee, 'Chose a competitor.', null, $this->key());

        $this->assertNotNull($response->getKey());
        $this->assertSame(1, ProposalClientResponse::query()->count());
    }

    public function test_outcome_becomes_lost(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);

        app(ProposalClientResponseService::class)->recordRejected($version, $employee, 'Chose a competitor.', null, $this->key());

        $this->assertSame(ProposalOutcome::Lost, $proposal->fresh()->outcome);
    }

    public function test_stage_becomes_customer_rejected(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);

        app(ProposalClientResponseService::class)->recordRejected($version, $employee, 'Chose a competitor.', null, $this->key());

        $this->assertSame(ProposalStage::CustomerRejected, $proposal->fresh()->stage);
    }

    public function test_no_billing_handoff_created(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [, $version] = $this->sentProposal($employee);

        app(ProposalClientResponseService::class)->recordRejected($version, $employee, 'Chose a competitor.', null, $this->key());

        $this->assertSame(0, ProposalBillingHandoff::query()->count());
    }

    public function test_subsequent_response_is_denied(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [, $version] = $this->sentProposal($employee);
        app(ProposalClientResponseService::class)->recordRejected($version, $employee, 'Chose a competitor.', null, $this->key());

        $this->expectException(LogicException::class);

        app(ProposalClientResponseService::class)->recordOtherAwaitFurtherContact($version->fresh(), $employee, 'Following up anyway.', $this->key());
    }
}
