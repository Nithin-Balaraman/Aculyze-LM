<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Enums\DemoMode;
use App\Enums\LeadStage;
use App\Enums\ProposalStage;
use App\Enums\UserRole;
use App\Exceptions\EmployeeDeletionFailedException;
use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Filament\Resources\ProposalResource\Pages\ListProposals;
use App\Models\CallRecord;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use App\Models\Prospect;
use App\Models\User;
use App\Services\EmployeeDeletionService;
use App\Services\ProposalCreationService;
use App\Services\ProposalVersionWorkflowService;
use App\Services\WorkflowTransitionService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Audit fix pass 1 (F1-F4 + G1): deletion safety across Proposal commercial
 * history, employee offboarding, and Demo.
 *
 * Written BEFORE the fixes, against the real creation paths
 * (ProposalCreationService, ProposalVersionWorkflowService,
 * WorkflowTransitionService) rather than hand-built rows, so each test
 * first reproduced the audit's actual failure — a raw RESTRICT
 * QueryException on Proposal delete, a silently deleted Proposal +
 * ProposalVersion history on employee offboarding, and a Demo that no
 * dependency surface knew about at all.
 */
class DeletionSafetyAuditFixTest extends TestCase
{
    use RefreshDatabase;

    private function service(): EmployeeDeletionService
    {
        return app(EmployeeDeletionService::class);
    }

    /** @return array{seniorManager: User, manager: User, employee: User} */
    private function hierarchy(): array
    {
        $seniorManager = User::factory()->admin()->create();
        $manager = User::factory()->create(['role' => UserRole::Manager, 'manager_id' => $seniorManager->id]);
        $employee = User::factory()->create(['role' => UserRole::Employee, 'manager_id' => $manager->id]);

        return compact('seniorManager', 'manager', 'employee');
    }

    /**
     * A Senior Manager in a brand-new, second organization — organization_id
     * is passed explicitly because UserFactory otherwise reuses whichever
     * Organization already exists (see its own docblock).
     */
    private function adminInANewOrganization(): User
    {
        $organization = Organization::factory()->create();

        return Tenancy::runAs(
            $organization->id,
            fn () => User::factory()->admin()->create(['organization_id' => $organization->id])
        );
    }

    private function leadFor(User $employee): Lead
    {
        $prospect = Prospect::factory()->create([
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
        ]);

        return Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => LeadStage::Validated,
            'temperature' => 'hot',
            'notes' => 'Validated in test fixture.',
        ]);
    }

    /** The real runtime path: Proposal + its V1 Draft, created atomically. */
    private function proposalWithV1(User $employee, ?Lead $lead = null): Proposal
    {
        $lead ??= $this->leadFor($employee);

        return app(ProposalCreationService::class)->createForLead($lead, [
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => ProposalStage::BeingPrepared,
        ]);
    }

    /** The real runtime path: a Demo reached through a Lead -> Demo transition. */
    private function demoFor(Lead $lead, User $employee): Demo
    {
        return app(WorkflowTransitionService::class)->transitionToDemo($lead, $lead, 'lead', [
            'demo_at' => now()->addDays(2),
            'mode' => DemoMode::OnSite,
            'location' => '14 Industrial Estate, Kochi',
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'product_service' => 'Aculyze analyser',
            'purpose' => 'Show the reporting module.',
        ]);
    }

    /** Gives a Version enough real content for ProposalVersionWorkflowService::submit(). */
    private function makeSubmittable(ProposalVersion $version): ProposalVersion
    {
        $version->forceFill([
            'customer_name_snapshot' => 'Acme Instruments',
            'payment_terms' => 'Net 30',
            'validity_terms' => '30 days',
        ])->save();

        ProposalVersionLine::create([
            'proposal_version_id' => $version->id,
            'line_number' => 1,
            'item_name' => 'Analyser',
            'quantity' => 1,
            'unit_price' => 1000,
        ]);

        return $version->fresh();
    }

    // =================================================================
    // F1 / G1 1-7 — PROPOSAL DELETE
    // =================================================================

    public function test_1_proposal_created_through_the_service_has_a_v1(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $proposal = $this->proposalWithV1($employee);

        $this->assertSame(1, $proposal->versions()->count());
        $this->assertNotNull($proposal->current_version_id);
        $this->assertSame(1, $proposal->currentVersion->version_number);
    }

    public function test_2_3_4_5_proposal_hard_delete_is_blocked_before_any_fk_exception(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $proposal = $this->proposalWithV1($employee);
        $version = $proposal->currentVersion;
        $line = ProposalVersionLine::create([
            'proposal_version_id' => $version->id,
            'line_number' => 1,
            'item_name' => 'Analyser',
            'quantity' => 1,
            'unit_price' => 1000,
        ]);

        // 2. The domain blocker exists and reports the Version, so no
        // caller ever has to reach the database to find out.
        $this->assertSame(['commercial Version(s)' => 1], $proposal->deletionBlockers());

        // 3/4/5. Nothing was removed.
        $this->assertDatabaseHas('proposals', ['id' => $proposal->id]);
        $this->assertDatabaseHas('proposal_versions', ['id' => $version->id]);
        $this->assertDatabaseHas('proposal_version_lines', ['id' => $line->id]);
    }

    public function test_2b_a_proposal_with_no_versions_is_still_deletable(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $lead = $this->leadFor($employee);
        $proposal = Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $lead->prospect_id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => ProposalStage::BeingPrepared,
        ]);

        $this->assertSame([], array_filter($proposal->deletionBlockers()));
    }

    public function test_6_single_filament_delete_action_surfaces_a_friendly_blocker(): void
    {
        ['seniorManager' => $admin, 'employee' => $employee] = $this->hierarchy();
        $proposal = $this->proposalWithV1($employee);
        $versionId = $proposal->current_version_id;

        $this->actingAs($admin);

        Livewire::test(ListProposals::class)
            ->callTableAction('delete', $proposal)
            ->assertNotified("Can't delete this proposal");

        $this->assertDatabaseHas('proposals', ['id' => $proposal->id]);
        $this->assertDatabaseHas('proposal_versions', ['id' => $versionId]);
    }

    public function test_7_bulk_delete_cannot_bypass_the_blocker(): void
    {
        ['seniorManager' => $admin, 'employee' => $employee] = $this->hierarchy();
        $blocked = $this->proposalWithV1($employee);

        $lead = $this->leadFor($employee);
        $deletable = Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $lead->prospect_id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => ProposalStage::BeingPrepared,
        ]);

        $this->actingAs($admin);

        Livewire::test(ListProposals::class)
            ->callTableBulkAction('delete', [$blocked, $deletable])
            ->assertNotified("Can't delete 1 of the selected proposals");

        // Established convention (App\Support\DeletionGuard::guardRecords):
        // the WHOLE batch is blocked, never a silent partial delete.
        $this->assertDatabaseHas('proposals', ['id' => $blocked->id]);
        $this->assertDatabaseHas('proposals', ['id' => $deletable->id]);
        $this->assertDatabaseHas('proposal_versions', ['id' => $blocked->current_version_id]);
    }

    // =================================================================
    // F2 / G1 8-13 — EMPLOYEE OFFBOARDING KEEPS ASSIGNED PROPOSALS
    // =================================================================

    public function test_8_to_13_offboarding_reassigns_the_proposal_instead_of_deleting_it(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $replacement = User::factory()->create();

        $proposal = $this->proposalWithV1($employee);       // 8.
        $version = $proposal->currentVersion;
        $lead = $proposal->lead;

        $this->service()->reassignAndDelete($employee, $replacement);   // 9.

        $fresh = Proposal::query()->find($proposal->id);

        $this->assertNotNull($fresh);
        $this->assertSame($proposal->id, $fresh->id);                    // 10.
        $this->assertSame($proposal->lead_id, $fresh->lead_id);
        $this->assertSame($proposal->prospect_id, $fresh->prospect_id);
        $this->assertSame($proposal->organization_id, $fresh->organization_id);
        $this->assertSame($proposal->stage, $fresh->stage);
        $this->assertSame($proposal->outcome, $fresh->outcome);

        $this->assertSame($version->id, $fresh->current_version_id);     // 11.
        $this->assertSame(1, $fresh->versions()->count());               // 12.
        $this->assertDatabaseHas('proposal_versions', [
            'id' => $version->id,
            'proposal_id' => $proposal->id,
            'version_number' => 1,
        ]);

        $this->assertSame($replacement->id, $fresh->assigned_to);        // 13.
        $this->assertSame($replacement->id, $fresh->created_by);

        // The Lead the surviving Proposal hangs off cannot be deleted
        // either — proposals.lead_id is RESTRICT.
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'assigned_to' => $replacement->id]);

        $this->assertDatabaseMissing('users', ['id' => $employee->id]);
    }

    public function test_option_b_also_preserves_assigned_proposals(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $replacement = User::factory()->create();
        $proposal = $this->proposalWithV1($employee);

        $this->service()->deleteEverything($employee, $replacement);

        $this->assertDatabaseHas('proposals', ['id' => $proposal->id, 'assigned_to' => $replacement->id]);
        $this->assertDatabaseHas('proposal_versions', ['id' => $proposal->current_version_id]);
    }

    public function test_a_lead_with_no_commercial_or_demo_history_is_still_deleted_on_offboarding(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $replacement = User::factory()->create();
        $lead = $this->leadFor($employee);

        $this->service()->reassignAndDelete($employee, $replacement);

        $this->assertDatabaseMissing('leads', ['id' => $lead->id]);
    }

    // =================================================================
    // F2 / G1 14-19 — VERSION ACTOR EVIDENCE IS A HARD BLOCK
    // =================================================================

    /** @return array{proposal: Proposal, version: ProposalVersion} */
    private function submittedVersion(User $employee, User $submitter): array
    {
        $proposal = $this->proposalWithV1($employee);
        $version = $this->makeSubmittable($proposal->currentVersion);
        app(ProposalVersionWorkflowService::class)->submit($version, $submitter);

        return ['proposal' => $proposal, 'version' => $version->fresh()];
    }

    public function test_14_submitted_by_blocks_hard_deletion_cleanly(): void
    {
        ['manager' => $manager, 'employee' => $employee] = $this->hierarchy();
        $replacement = User::factory()->create(['role' => UserRole::Manager, 'manager_id' => User::query()->where('role', UserRole::Admin)->value('id')]);

        ['version' => $version] = $this->submittedVersion($employee, $manager);
        $this->assertSame($manager->id, $version->submitted_by);

        try {
            $this->service()->reassignAndDelete($manager, $replacement);
            $this->fail('Expected the deletion to be blocked.');
        } catch (EmployeeDeletionFailedException $e) {
            $this->assertStringContainsString('permanent', $e->getMessage());
        }

        $this->assertDatabaseHas('users', ['id' => $manager->id]);
        $this->assertDatabaseHas('proposal_versions', ['id' => $version->id, 'submitted_by' => $manager->id]);
    }

    public function test_15_approved_by_blocks_hard_deletion_cleanly(): void
    {
        ['seniorManager' => $seniorManager, 'manager' => $manager, 'employee' => $employee] = $this->hierarchy();

        ['version' => $version] = $this->submittedVersion($employee, $manager);
        app(ProposalVersionWorkflowService::class)->approve($version, $seniorManager);
        $version->refresh();

        $this->assertSame($seniorManager->id, $version->approved_by);

        $breakdown = $this->service()->dependencyBreakdown($seniorManager);
        $this->assertSame(1, $breakdown['versionsApproved']);

        $this->expectException(EmployeeDeletionFailedException::class);
        $this->service()->reassignAndDelete($seniorManager, $manager);
    }

    public function test_16_returned_by_blocks_hard_deletion_cleanly(): void
    {
        ['seniorManager' => $seniorManager, 'manager' => $manager, 'employee' => $employee] = $this->hierarchy();

        ['version' => $version] = $this->submittedVersion($employee, $manager);
        app(ProposalVersionWorkflowService::class)->returnForRevision($version, $seniorManager, 'Pricing needs rework.');
        $version->refresh();

        $this->assertSame($seniorManager->id, $version->returned_by);
        $this->assertSame(1, $this->service()->dependencyBreakdown($seniorManager)['versionsReturned']);

        $this->expectException(EmployeeDeletionFailedException::class);
        $this->service()->reassignAndDelete($seniorManager, $manager);
    }

    public function test_17_combined_actor_references_are_reported_accurately(): void
    {
        ['seniorManager' => $seniorManager, 'manager' => $manager, 'employee' => $employee] = $this->hierarchy();

        // Self-approval is never allowed, so a combined actor reference is
        // necessarily across two different Proposals: this Senior Manager
        // submitted one Version themselves and approved another.
        $this->submittedVersion($employee, $seniorManager);

        ['version' => $theirs] = $this->submittedVersion($employee, $manager);
        app(ProposalVersionWorkflowService::class)->approve($theirs, $seniorManager);

        $breakdown = $this->service()->dependencyBreakdown($seniorManager);

        $this->assertSame(1, $breakdown['versionsSubmitted']);
        $this->assertSame(1, $breakdown['versionsApproved']);
        $this->assertSame(0, $breakdown['versionsReturned']);
    }

    public function test_18_and_19_the_blocker_runs_before_any_partial_reassignment(): void
    {
        ['manager' => $manager, 'employee' => $employee] = $this->hierarchy();
        $replacement = User::factory()->create(['role' => UserRole::Manager, 'manager_id' => User::query()->where('role', UserRole::Admin)->value('id')]);

        ['version' => $version, 'proposal' => $proposal] = $this->submittedVersion($employee, $manager);

        // Give the actor real, ordinarily-reassignable records too — none
        // of these may move before the hard block fires.
        $prospect = Prospect::factory()->create(['assigned_to' => $manager->id, 'created_by' => $manager->id]);
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $manager->id,
            'called_at' => now(),
            'outcome' => CallOutcome::NoAnswer,
        ]);

        try {
            $this->service()->reassignAndDelete($manager, $replacement);
            $this->fail('Expected the deletion to be blocked.');
        } catch (EmployeeDeletionFailedException) {
            // expected
        }

        // 18. No partial movement.
        $this->assertDatabaseHas('call_records', ['id' => $call->id, 'user_id' => $manager->id]);
        $this->assertDatabaseHas('prospects', ['id' => $prospect->id, 'assigned_to' => $manager->id]);
        $this->assertDatabaseHas('users', ['id' => $employee->id, 'manager_id' => $manager->id]);

        // 19. Actor ids unchanged.
        $this->assertDatabaseHas('proposal_versions', ['id' => $version->id, 'submitted_by' => $manager->id]);
        $this->assertDatabaseHas('proposals', ['id' => $proposal->id, 'assigned_to' => $employee->id]);
    }

    public function test_the_actor_block_also_applies_to_the_no_dependencies_shortcut(): void
    {
        ['seniorManager' => $seniorManager, 'manager' => $manager, 'employee' => $employee] = $this->hierarchy();

        // A second Senior Manager who owns nothing at all but approved one
        // Version — the "nothing to clean up" path must not delete them.
        $approver = User::factory()->admin()->create();
        ['version' => $version] = $this->submittedVersion($employee, $manager);
        app(ProposalVersionWorkflowService::class)->approve($version, $approver);

        $this->assertTrue($this->service()->hasDependencies($approver));

        $this->expectException(EmployeeDeletionFailedException::class);
        $this->service()->deleteWithoutDependencies($approver);
    }

    // =================================================================
    // F3 / G1 20-25 — DEMO IN EMPLOYEE OFFBOARDING
    // =================================================================

    public function test_20_and_21_demo_appears_in_the_dependency_surfaces(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $lead = $this->leadFor($employee);
        $this->demoFor($lead, $employee);

        $breakdown = $this->service()->dependencyBreakdown($employee);

        $this->assertSame(1, $breakdown['demos']);                       // 20.
        $this->assertTrue($this->service()->hasDependencies($employee));
        $this->assertTrue($this->service()->requiresReplacement($employee)); // 21.
    }

    public function test_22_to_25_offboarding_transfers_the_demo_and_deletes_nothing(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $replacement = User::factory()->create();
        $lead = $this->leadFor($employee);
        $demo = $this->demoFor($lead, $employee);

        $this->service()->reassignAndDelete($employee, $replacement);   // 24. no FK exception

        $this->assertDatabaseHas('demos', [                              // 22/23.
            'id' => $demo->id,
            'assigned_to' => $replacement->id,
            'created_by' => $replacement->id,                            // 25.
            'lead_id' => $lead->id,
        ]);
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'assigned_to' => $replacement->id]);
        $this->assertDatabaseMissing('users', ['id' => $employee->id]);
    }

    public function test_a_demo_created_by_the_employee_but_owned_by_someone_else_only_changes_creatorship(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $owner = User::factory()->create();
        $replacement = User::factory()->create();

        $lead = $this->leadFor($owner);
        $demo = app(WorkflowTransitionService::class)->transitionToDemo($lead, $lead, 'lead', [
            'demo_at' => now()->addDay(),
            'mode' => DemoMode::OnSite,
            'location' => 'Site B',
            'assigned_to' => $owner->id,
            'created_by' => $employee->id,
        ]);

        $this->assertTrue($this->service()->requiresReplacement($employee));

        $this->service()->reassignAndDelete($employee, $replacement);

        $this->assertDatabaseHas('demos', [
            'id' => $demo->id,
            'assigned_to' => $owner->id,
            'created_by' => $replacement->id,
        ]);
    }

    public function test_user_deletion_blockers_name_demos_and_version_actors(): void
    {
        ['manager' => $manager, 'employee' => $employee] = $this->hierarchy();
        $lead = $this->leadFor($employee);
        $this->demoFor($lead, $employee);
        $this->submittedVersion($employee, $manager);

        $employeeBlockers = $employee->deletionBlockers();
        $this->assertSame(1, $employeeBlockers['assigned Demo(s)']);
        $this->assertSame(1, $employeeBlockers['created Demo(s)']);

        $managerBlockers = $manager->deletionBlockers();
        $this->assertSame(1, $managerBlockers['Proposal Version(s) they submitted']);
    }

    // =================================================================
    // F4 / G1 26-30 — LEAD DELETION BLOCKER FOR DEMO
    // =================================================================

    public function test_26_lead_deletion_blockers_include_demo(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $lead = $this->leadFor($employee);
        $this->demoFor($lead, $employee);

        $this->assertSame(['Proposal' => 0, 'Demo(s)' => 1], $lead->fresh()->deletionBlockers());
    }

    public function test_27_to_29_lead_delete_is_blocked_and_nothing_is_removed(): void
    {
        ['seniorManager' => $admin, 'employee' => $employee] = $this->hierarchy();
        $lead = $this->leadFor($employee);
        $demo = $this->demoFor($lead, $employee);

        $this->actingAs($admin);

        Livewire::test(ListLeads::class)
            ->callTableAction('delete', $lead)
            ->assertNotified("Can't delete this lead");

        $this->assertDatabaseHas('leads', ['id' => $lead->id]);
        $this->assertDatabaseHas('demos', ['id' => $demo->id]);
    }

    public function test_30_a_lead_with_both_a_proposal_and_demos_reports_both(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $lead = $this->leadFor($employee);
        $this->demoFor($lead, $employee);
        $this->demoFor($lead, $employee);
        $this->proposalWithV1($employee, $lead);

        $this->assertSame(['Proposal' => 1, 'Demo(s)' => 2], $lead->fresh()->deletionBlockers());
    }

    // =================================================================
    // G1 31-33 — TENANCY
    // =================================================================

    public function test_31_a_replacement_from_another_organization_is_rejected(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $this->proposalWithV1($employee);

        $outsider = $this->adminInANewOrganization();

        $this->assertNotSame($employee->organization_id, $outsider->organization_id);

        $this->expectException(EmployeeDeletionFailedException::class);
        $this->expectExceptionMessage('different organization');

        $this->service()->reassignAndDelete($employee, $outsider);
    }

    public function test_32_no_cross_org_demo_or_proposal_reassignment_survives_a_rejected_replacement(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $proposal = $this->proposalWithV1($employee);
        $demo = $this->demoFor($proposal->lead, $employee);

        $outsider = $this->adminInANewOrganization();

        try {
            $this->service()->reassignAndDelete($employee, $outsider);
            $this->fail('Expected a cross-organization replacement to be rejected.');
        } catch (EmployeeDeletionFailedException) {
            // expected
        }

        $this->assertDatabaseHas('proposals', ['id' => $proposal->id, 'assigned_to' => $employee->id]);
        $this->assertDatabaseHas('demos', ['id' => $demo->id, 'assigned_to' => $employee->id]);
        $this->assertDatabaseHas('users', ['id' => $employee->id]);
    }

    public function test_33_dependency_queries_do_not_leak_cross_organization_counts(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $homeOrganizationId = $employee->organization_id;

        // A whole second organization's worth of records, none of which may
        // ever be counted against (or reachable from) the first one.
        $stranger = $this->adminInANewOrganization();
        Tenancy::runAs($stranger->organization_id, function () use ($stranger) {
            $lead = $this->leadFor($stranger);
            $this->proposalWithV1($stranger, $lead);
            $this->demoFor($lead, $stranger);
        });

        $breakdown = Tenancy::runAs($homeOrganizationId, fn () => $this->service()->dependencyBreakdown($employee));

        $this->assertSame(0, array_sum($breakdown));
        $this->assertFalse(Tenancy::runAs($homeOrganizationId, fn () => $this->service()->hasDependencies($employee)));
    }
}
