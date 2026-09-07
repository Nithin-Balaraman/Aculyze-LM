<?php

namespace Tests\Feature;

use App\Enums\ProposalVersionLifecycle;
use App\Enums\UserRole;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use App\Models\ProposalVersionLineTaxComponent;
use App\Models\Prospect;
use App\Models\User;
use App\Services\ProposalVersionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Phase 4A-2.2: ProposalVersionWorkflowService::returnForRevision() —
 * Submitted -> ReturnedForRevision, atomically creating a new Draft. The
 * old Version is never reopened; it becomes permanent, frozen history.
 */
class ProposalVersionWorkflowServiceReturnForRevisionTest extends TestCase
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

    private function submittedVersionWithLinesAndTax(Proposal $proposal, User $submitter): ProposalVersion
    {
        $version = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'version_number' => 1,
            'lifecycle_status' => ProposalVersionLifecycle::Draft,
            'customer_name_snapshot' => 'Acme Corp',
            'customer_gstin_snapshot' => '33AAAAA0000A1Z5',
            'billing_address_snapshot' => '1 Industrial Estate',
            'billing_state_snapshot' => 'Tamil Nadu',
            'place_of_supply_snapshot' => 'Tamil Nadu',
        ]);

        $line = ProposalVersionLine::create([
            'proposal_version_id' => $version->id,
            'line_number' => 1,
            'item_name' => 'Widget',
            'quantity' => 2,
            'unit_price' => 500,
        ]);

        ProposalVersionLineTaxComponent::create([
            'proposal_version_line_id' => $line->id,
            'component_type' => 'cgst',
            'rate' => 9,
            'amount' => 90,
        ]);

        $proposal->forceFill(['current_version_id' => $version->id])->save();

        app(ProposalVersionWorkflowService::class)->submit($version->fresh(), $submitter);

        return $version->fresh();
    }

    public function test_senior_manager_can_return_current_submitted_version_with_mandatory_reason(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersionWithLinesAndTax($proposal, $manager);

        $newDraft = app(ProposalVersionWorkflowService::class)->returnForRevision($version, $seniorManager, 'Pricing is too aggressive.');

        $this->assertSame(ProposalVersionLifecycle::Draft, $newDraft->lifecycle_status);
        $this->assertNotSame($version->id, $newDraft->id);
    }

    public function test_blank_reason_is_rejected(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersionWithLinesAndTax($proposal, $manager);

        $this->expectException(LogicException::class);
        app(ProposalVersionWorkflowService::class)->returnForRevision($version, $seniorManager, '');
    }

    public function test_manager_cannot_return_for_revision(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersionWithLinesAndTax($proposal, $manager);

        $this->expectException(LogicException::class);
        app(ProposalVersionWorkflowService::class)->returnForRevision($version, $manager, 'reason');
    }

    public function test_employee_cannot_return_for_revision(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersionWithLinesAndTax($proposal, $manager);

        $this->expectException(LogicException::class);
        app(ProposalVersionWorkflowService::class)->returnForRevision($version, $employee, 'reason');
    }

    public function test_return_creates_exactly_one_new_draft(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersionWithLinesAndTax($proposal, $manager);

        app(ProposalVersionWorkflowService::class)->returnForRevision($version, $seniorManager, 'Revise pricing.');

        $this->assertSame(2, DB::table('proposal_versions')->where('proposal_id', $proposal->id)->count());
        $this->assertSame(1, DB::table('proposal_versions')->where('proposal_id', $proposal->id)->where('lifecycle_status', 'draft')->count());
    }

    public function test_old_version_becomes_returned_for_revision_with_full_evidence(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersionWithLinesAndTax($proposal, $manager);

        $newDraft = app(ProposalVersionWorkflowService::class)->returnForRevision($version, $seniorManager, 'Revise pricing.');

        $fresh = $version->fresh();
        $this->assertSame(ProposalVersionLifecycle::ReturnedForRevision, $fresh->lifecycle_status);
        $this->assertSame($seniorManager->id, $fresh->returned_by);
        $this->assertNotNull($fresh->returned_at);
        $this->assertSame('Revise pricing.', $fresh->return_reason);
        $this->assertNotNull($fresh->superseded_at);
        $this->assertSame($newDraft->id, $fresh->superseded_by_version_id);
    }

    public function test_proposal_current_version_id_points_to_new_draft(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersionWithLinesAndTax($proposal, $manager);

        $newDraft = app(ProposalVersionWorkflowService::class)->returnForRevision($version, $seniorManager, 'Revise pricing.');

        $this->assertSame($newDraft->id, $proposal->fresh()->current_version_id);
    }

    public function test_new_draft_version_number_and_legacy_flag_are_correct(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersionWithLinesAndTax($proposal, $manager);

        $newDraft = app(ProposalVersionWorkflowService::class)->returnForRevision($version, $seniorManager, 'Revise pricing.');

        $this->assertSame(2, $newDraft->version_number);
        $this->assertFalse($newDraft->is_legacy_backfill);
    }

    public function test_customer_and_commercial_snapshot_is_cloned_exactly(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersionWithLinesAndTax($proposal, $manager);

        $newDraft = app(ProposalVersionWorkflowService::class)->returnForRevision($version, $seniorManager, 'Revise pricing.');

        $this->assertSame('Acme Corp', $newDraft->customer_name_snapshot);
        $this->assertSame('33AAAAA0000A1Z5', $newDraft->customer_gstin_snapshot);
        $this->assertSame('1 Industrial Estate', $newDraft->billing_address_snapshot);
        $this->assertSame('Tamil Nadu', $newDraft->billing_state_snapshot);
        $this->assertSame('Tamil Nadu', $newDraft->place_of_supply_snapshot);
    }

    public function test_line_rows_are_cloned_to_independent_ids(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersionWithLinesAndTax($proposal, $manager);
        $oldLineId = $version->lines()->first()->id;

        $newDraft = app(ProposalVersionWorkflowService::class)->returnForRevision($version, $seniorManager, 'Revise pricing.');

        $newLine = $newDraft->lines()->first();
        $this->assertNotSame($oldLineId, $newLine->id);
        $this->assertSame('Widget', $newLine->item_name);
        $this->assertEquals(2, $newLine->quantity);
        $this->assertEquals(500, $newLine->unit_price);
    }

    public function test_tax_components_are_cloned_to_independent_ids(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersionWithLinesAndTax($proposal, $manager);
        $oldComponentId = $version->lines()->first()->taxComponents()->first()->id;

        $newDraft = app(ProposalVersionWorkflowService::class)->returnForRevision($version, $seniorManager, 'Revise pricing.');

        $newComponent = $newDraft->lines()->first()->taxComponents()->first();
        $this->assertNotSame($oldComponentId, $newComponent->id);
        $this->assertEquals(90, $newComponent->amount);
    }

    public function test_workflow_evidence_is_not_cloned_onto_the_new_draft(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersionWithLinesAndTax($proposal, $manager);

        $newDraft = app(ProposalVersionWorkflowService::class)->returnForRevision($version, $seniorManager, 'Revise pricing.');

        $this->assertNull($newDraft->submitted_by);
        $this->assertNull($newDraft->submitted_at);
        $this->assertNull($newDraft->approved_by);
        $this->assertNull($newDraft->returned_by);
        $this->assertNull($newDraft->superseded_at);
        $this->assertNull($newDraft->superseded_by_version_id);
        $this->assertNull($newDraft->manager_reviewed_by);
    }

    public function test_second_return_or_retry_cannot_create_another_draft(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersionWithLinesAndTax($proposal, $manager);

        app(ProposalVersionWorkflowService::class)->returnForRevision($version, $seniorManager, 'Revise pricing.');

        try {
            app(ProposalVersionWorkflowService::class)->returnForRevision($version, $seniorManager, 'Again.');
            $this->fail('Expected a second Return on the same (already-returned) Version to be rejected.');
        } catch (LogicException) {
            // expected
        }

        $this->assertSame(2, DB::table('proposal_versions')->where('proposal_id', $proposal->id)->count());
    }

    /**
     * The active-Draft-conflict check runs BEFORE any mutation of the old
     * Version — a conflict must never leave the old Version partially
     * updated.
     */
    public function test_active_draft_conflict_fails_cleanly_without_partially_mutating_the_old_version(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersionWithLinesAndTax($proposal, $manager);

        // Simulate an already-existing conflicting Draft for this Proposal
        // (shouldn't happen in practice given draft_lock_key, but the
        // service must still check explicitly and fail closed).
        DB::table('proposal_versions')->insert([
            'organization_id' => $proposal->organization_id,
            'proposal_id' => $proposal->id,
            'version_number' => 99,
            'lifecycle_status' => 'draft',
            'is_legacy_backfill' => false,
            'currency_code' => 'INR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(ProposalVersionWorkflowService::class)->returnForRevision($version, $seniorManager, 'Revise pricing.');
            $this->fail('Expected the active-Draft conflict to be rejected.');
        } catch (LogicException) {
            // expected
        }

        $fresh = $version->fresh();
        $this->assertSame(ProposalVersionLifecycle::Submitted, $fresh->lifecycle_status);
        $this->assertNull($fresh->returned_by);
        $this->assertNull($fresh->superseded_at);
    }

    public function test_return_writes_both_returned_and_revision_created_audit_events(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersionWithLinesAndTax($proposal, $manager);

        $newDraft = app(ProposalVersionWorkflowService::class)->returnForRevision($version, $seniorManager, 'Revise pricing.');

        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'ProposalVersion',
            'entity_id' => $version->id,
            'action' => 'proposal_version_returned_for_revision',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'ProposalVersion',
            'entity_id' => $newDraft->id,
            'action' => 'proposal_version_revision_created',
        ]);
    }

    public function test_return_never_touches_parent_proposal_outcome_or_winning_version(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersionWithLinesAndTax($proposal, $manager);

        app(ProposalVersionWorkflowService::class)->returnForRevision($version, $seniorManager, 'Revise pricing.');

        $fresh = $proposal->fresh();
        $this->assertNull($fresh->outcome);
        $this->assertNull($fresh->winning_version_id);
    }
}
