<?php

namespace Tests\Feature;

use App\Enums\ProposalVersionLifecycle;
use App\Enums\UserRole;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use App\Models\Prospect;
use App\Models\User;
use App\Services\ProposalVersionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Phase 4A-2.2: ProposalVersionWorkflowService::approve() — Submitted ->
 * Approved. Senior Manager only, and the self-approval prohibition
 * (locked Decision 19: the submitter may never also be the approver, even
 * as Senior Manager).
 */
class ProposalVersionWorkflowServiceApproveTest extends TestCase
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

    private function proposalFor(User $employee): Proposal
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);

        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => 'validated',
            'temperature' => 'hot',
            'notes' => 'Validated in test fixture.',
        ]);

        return Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => 'being_prepared',
        ]);
    }

    private function submittedVersion(Proposal $proposal, User $submitter): ProposalVersion
    {
        $version = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'version_number' => 1,
            'lifecycle_status' => ProposalVersionLifecycle::Draft,
            'customer_name_snapshot' => 'Acme Corp',
        ]);

        ProposalVersionLine::create([
            'proposal_version_id' => $version->id,
            'line_number' => 1,
            'item_name' => 'Widget',
            'quantity' => 2,
            'unit_price' => 500,
        ]);

        $proposal->forceFill(['current_version_id' => $version->id])->save();

        app(ProposalVersionWorkflowService::class)->submit($version->fresh(), $submitter);

        return $version->fresh();
    }

    public function test_senior_manager_can_approve_current_submitted_version(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersion($proposal, $manager);

        app(ProposalVersionWorkflowService::class)->approve($version, $seniorManager, 'Looks good.');

        $fresh = $version->fresh();
        $this->assertSame(ProposalVersionLifecycle::Approved, $fresh->lifecycle_status);
        $this->assertSame($seniorManager->id, $fresh->approved_by);
        $this->assertNotNull($fresh->approved_at);
        $this->assertSame('Looks good.', $fresh->approval_comment);
    }

    public function test_manager_cannot_approve(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersion($proposal, $manager);

        $anotherManager = User::factory()->create([
            'role' => UserRole::Manager,
            'organization_id' => $manager->organization_id,
        ]);

        $this->expectException(LogicException::class);
        app(ProposalVersionWorkflowService::class)->approve($version, $anotherManager);
    }

    public function test_employee_cannot_approve(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersion($proposal, $manager);

        $this->expectException(LogicException::class);
        app(ProposalVersionWorkflowService::class)->approve($version, $employee);
    }

    /** Locked Decision 19: self-approval is prohibited even for the submitter's own Senior Manager rank. */
    public function test_the_submitter_cannot_self_approve_the_same_version_even_as_senior_manager(): void
    {
        $employee = User::factory()->create(['role' => UserRole::Employee]);
        $seniorManagerWhoSubmits = User::factory()->admin()->create(['organization_id' => $employee->organization_id]);

        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersion($proposal, $seniorManagerWhoSubmits);

        $this->assertSame($seniorManagerWhoSubmits->id, $version->fresh()->submitted_by);

        $this->expectException(LogicException::class);
        app(ProposalVersionWorkflowService::class)->approve($version, $seniorManagerWhoSubmits);
    }

    /** Positive control: a DIFFERENT Senior Manager may approve what the first one submitted. */
    public function test_a_different_senior_manager_can_approve_what_another_senior_manager_submitted(): void
    {
        $employee = User::factory()->create(['role' => UserRole::Employee]);
        $submittingSeniorManager = User::factory()->admin()->create(['organization_id' => $employee->organization_id]);
        $approvingSeniorManager = User::factory()->admin()->create(['organization_id' => $employee->organization_id]);

        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersion($proposal, $submittingSeniorManager);

        app(ProposalVersionWorkflowService::class)->approve($version, $approvingSeniorManager);

        $this->assertSame(ProposalVersionLifecycle::Approved, $version->fresh()->lifecycle_status);
        $this->assertSame($approvingSeniorManager->id, $version->fresh()->approved_by);
    }

    public function test_wrong_lifecycle_cannot_be_approved(): void
    {
        ['employee' => $employee, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'version_number' => 1,
            'lifecycle_status' => ProposalVersionLifecycle::Draft,
        ]);
        $proposal->forceFill(['current_version_id' => $version->id])->save();

        $this->expectException(LogicException::class);
        app(ProposalVersionWorkflowService::class)->approve($version, $seniorManager);
    }

    public function test_non_current_version_cannot_be_approved(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersion($proposal, $manager);

        // Move current_version_id elsewhere without touching $version's own lifecycle.
        $otherVersion = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'version_number' => 2,
            'lifecycle_status' => ProposalVersionLifecycle::Sent,
        ]);
        $proposal->forceFill(['current_version_id' => $otherVersion->id])->save();

        $this->expectException(LogicException::class);
        app(ProposalVersionWorkflowService::class)->approve($version, $seniorManager);
    }

    public function test_repeated_approve_or_stale_request_fails_cleanly(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersion($proposal, $manager);

        app(ProposalVersionWorkflowService::class)->approve($version, $seniorManager);
        $firstApprovedAt = $version->fresh()->approved_at;

        try {
            app(ProposalVersionWorkflowService::class)->approve($version, $seniorManager);
            $this->fail('Expected a repeated Approve to be rejected.');
        } catch (LogicException) {
            // expected
        }

        $this->assertTrue($firstApprovedAt->equalTo($version->fresh()->approved_at));
        $this->assertSame(1, DB::table('audit_events')
            ->where('entity_type', 'ProposalVersion')
            ->where('entity_id', $version->id)
            ->where('action', 'proposal_version_approved')
            ->count());
    }

    public function test_approve_writes_a_correctly_shaped_audit_event(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersion($proposal, $manager);

        app(ProposalVersionWorkflowService::class)->approve($version, $seniorManager, 'Fine.');

        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'ProposalVersion',
            'entity_id' => $version->id,
            'action' => 'proposal_version_approved',
        ]);
    }

    public function test_approve_never_touches_parent_proposal_outcome_or_winning_version(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersion($proposal, $manager);

        app(ProposalVersionWorkflowService::class)->approve($version, $seniorManager);

        $fresh = $proposal->fresh();
        $this->assertNull($fresh->outcome);
        $this->assertNull($fresh->winning_version_id);
    }

    public function test_manager_reviewed_fields_remain_null_after_approve(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersion($proposal, $manager);

        app(ProposalVersionWorkflowService::class)->approve($version, $seniorManager);

        $fresh = $version->fresh();
        $this->assertNull($fresh->manager_reviewed_by);
        $this->assertNull($fresh->manager_reviewed_at);
        $this->assertNull($fresh->manager_review_comment);
    }
}
