<?php

namespace Tests\Feature;

use App\Enums\ProposalOutcome;
use App\Enums\ProposalVersionLifecycle;
use App\Enums\UserRole;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use App\Models\Prospect;
use App\Models\User;
use App\Policies\ProposalClientResponsePolicy;
use App\Services\ProposalClientResponseService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * Phase 4A-3.4, Sections C/E: who may record a client response, exact
 * response-Version eligibility (need not be current), and the terminal-
 * response rule (Won/Lost refuses further responses).
 */
class ProposalClientResponseAuthorizationTest extends TestCase
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

    public function test_assigned_employee_can_record_all_five_response_types(): void
    {
        ['employee' => $employee] = $this->hierarchy();

        foreach (['accepted', 'revision', 'moretime', 'rejected', 'other'] as $flavor) {
            [, $version] = $this->sentProposal($employee);
            $this->assertResponseRecordedForFlavor($flavor, $version, $employee);
        }
    }

    public function test_manager_in_hierarchy_can_record(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        [, $version] = $this->sentProposal($employee);

        $response = app(ProposalClientResponseService::class)->recordAccepted($version, $manager, null, $this->key());

        $this->assertSame($manager->id, $response->recorded_by);
    }

    public function test_senior_manager_can_record(): void
    {
        ['employee' => $employee, 'seniorManager' => $seniorManager] = $this->hierarchy();
        [, $version] = $this->sentProposal($employee);

        $response = app(ProposalClientResponseService::class)->recordAccepted($version, $seniorManager, null, $this->key());

        $this->assertSame($seniorManager->id, $response->recorded_by);
    }

    public function test_unrelated_employee_is_denied(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        [, $version] = $this->sentProposal($employee);
        $unrelated = User::factory()->create(['role' => UserRole::Employee, 'manager_id' => $manager->id]);

        $this->expectException(LogicException::class);

        app(ProposalClientResponseService::class)->recordAccepted($version, $unrelated, null, $this->key());
    }

    public function test_cross_tenant_is_denied(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);

        $organizationB = Organization::factory()->create();
        $managerB = Tenancy::runAs($organizationB->id, fn () => User::factory()->create([
            'organization_id' => $organizationB->id, 'role' => UserRole::Manager,
        ]));

        $this->assertFalse(app(ProposalClientResponsePolicy::class)->recordClientResponse($managerB, $proposal));
    }

    public function test_response_version_must_belong_to_the_proposal(): void
    {
        ['employee' => $employeeA] = $this->hierarchy();
        ['employee' => $employeeB] = $this->hierarchy();
        [, $versionA] = $this->sentProposal($employeeA);
        [$proposalB] = $this->sentProposal($employeeB);

        $this->expectException(LogicException::class);

        app(ProposalClientResponseService::class)->recordAccepted($versionA->fresh(), $employeeB, null, $this->key());
    }

    public function test_response_version_must_be_sent(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);
        $version->forceFill(['lifecycle_status' => ProposalVersionLifecycle::Approved])->save();

        $this->expectException(LogicException::class);

        app(ProposalClientResponseService::class)->recordAccepted($version->fresh(), $employee, null, $this->key());
    }

    public function test_response_version_may_be_non_current(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        [$proposal, $v1] = $this->sentProposal($employee);

        // V2 becomes current (a Draft), V1 stays Sent and non-current.
        $v2 = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'version_number' => 2,
            'lifecycle_status' => ProposalVersionLifecycle::Draft,
        ]);
        $proposal->forceFill(['current_version_id' => $v2->id])->save();

        $response = app(ProposalClientResponseService::class)->recordAccepted($v1->fresh(), $employee, null, $this->key());

        $this->assertSame($v1->getKey(), $response->proposal_version_id);
        $this->assertNotEquals($proposal->fresh()->current_version_id, $response->proposal_version_id);
    }

    public function test_terminal_won_refuses_further_responses(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);
        app(ProposalClientResponseService::class)->recordAccepted($version->fresh(), $employee, null, $this->key());

        $this->assertSame(ProposalOutcome::Won, $proposal->fresh()->outcome);

        $this->expectException(LogicException::class);

        app(ProposalClientResponseService::class)->recordRevisionRequested($version->fresh(), $employee, 'Too late', null, $this->key());
    }

    public function test_terminal_lost_refuses_further_responses(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);
        app(ProposalClientResponseService::class)->recordRejected($version->fresh(), $employee, 'Went with a competitor.', null, $this->key());

        $this->assertSame(ProposalOutcome::Lost, $proposal->fresh()->outcome);

        $this->expectException(LogicException::class);

        app(ProposalClientResponseService::class)->recordAccepted($version->fresh(), $employee, null, $this->key());
    }

    private function assertResponseRecordedForFlavor(string $flavor, ProposalVersion $version, User $employee): void
    {
        $service = app(ProposalClientResponseService::class);

        $response = match ($flavor) {
            'accepted' => $service->recordAccepted($version, $employee, null, $this->key()),
            'revision' => $service->recordRevisionRequested($version, $employee, 'Change something', null, $this->key()),
            'moretime' => $service->recordMoreTime($version, $employee, now()->addDay(), 'Budget review', null, null, null, $this->key()),
            'rejected' => $service->recordRejected($version, $employee, 'No longer needed.', null, $this->key()),
            'other' => $service->recordOtherAwaitFurtherContact($version, $employee, 'Will call back next month.', $this->key()),
        };

        $this->assertNotNull($response->getKey());
        $this->assertSame($employee->id, $response->recorded_by);
    }
}
