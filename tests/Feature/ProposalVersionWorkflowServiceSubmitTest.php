<?php

namespace Tests\Feature;

use App\Enums\ProposalVersionLifecycle;
use App\Enums\UserRole;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use App\Models\ProposalVersionLineTaxComponent;
use App\Models\Prospect;
use App\Models\User;
use App\Services\ProposalVersionWorkflowService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Phase 4A-2.2: ProposalVersionWorkflowService::submit() — Draft ->
 * Submitted. Structural validation only (no financial recalculation —
 * that is 4A-2.3's boundary).
 */
class ProposalVersionWorkflowServiceSubmitTest extends TestCase
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

    /** A structurally-complete Draft — passes every Submit prerequisite. */
    private function completeDraft(Proposal $proposal): ProposalVersion
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

        return $version->fresh();
    }

    public function test_authorized_manager_can_submit_current_draft(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->completeDraft($proposal);

        app(ProposalVersionWorkflowService::class)->submit($version, $manager);

        $fresh = $version->fresh();
        $this->assertSame(ProposalVersionLifecycle::Submitted, $fresh->lifecycle_status);
        $this->assertSame($manager->id, $fresh->submitted_by);
        $this->assertNotNull($fresh->submitted_at);
    }

    /** Senior Manager follows the same Manager-or-above authorization convention. */
    public function test_senior_manager_can_submit_current_draft(): void
    {
        ['employee' => $employee, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->completeDraft($proposal);

        app(ProposalVersionWorkflowService::class)->submit($version, $seniorManager);

        $this->assertSame(ProposalVersionLifecycle::Submitted, $version->fresh()->lifecycle_status);
    }

    public function test_employee_cannot_submit(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->completeDraft($proposal);

        $this->expectException(LogicException::class);
        app(ProposalVersionWorkflowService::class)->submit($version, $employee);
    }

    public function test_unrelated_out_of_hierarchy_manager_cannot_submit(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->completeDraft($proposal);

        $seniorManagerB = User::factory()->admin()->create(['organization_id' => $employee->organization_id]);
        $unrelatedManager = User::factory()->create([
            'role' => UserRole::Manager,
            'manager_id' => $seniorManagerB->id,
            'organization_id' => $employee->organization_id,
        ]);

        $this->expectException(LogicException::class);
        app(ProposalVersionWorkflowService::class)->submit($version, $unrelatedManager);
    }

    public function test_cross_organization_actor_cannot_submit(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->completeDraft($proposal);

        $otherOrg = Organization::factory()->create();
        $otherManager = Tenancy::runAs($otherOrg->id, fn () => User::factory()->create([
            'role' => UserRole::Manager,
            'organization_id' => $otherOrg->id,
        ]));

        $this->expectException(LogicException::class);
        app(ProposalVersionWorkflowService::class)->submit($version, $otherManager);
    }

    public function test_non_current_draft_cannot_submit(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->completeDraft($proposal);

        // A second, non-current Draft is impossible under the draft_lock_key
        // invariant while $version is still Draft — simulate "non-current"
        // by moving current_version_id elsewhere without touching lifecycle.
        $otherVersion = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'version_number' => 2,
            'lifecycle_status' => ProposalVersionLifecycle::Sent,
        ]);
        $proposal->forceFill(['current_version_id' => $otherVersion->id])->save();

        $this->expectException(LogicException::class);
        app(ProposalVersionWorkflowService::class)->submit($version, $manager);
    }

    public function test_non_draft_lifecycle_cannot_submit(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'version_number' => 1,
            'lifecycle_status' => ProposalVersionLifecycle::Sent,
        ]);
        $proposal->forceFill(['current_version_id' => $version->id])->save();

        $this->expectException(LogicException::class);
        app(ProposalVersionWorkflowService::class)->submit($version, $manager);
    }

    public function test_successful_submit_populates_expected_fields(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->completeDraft($proposal);

        app(ProposalVersionWorkflowService::class)->submit($version, $manager);

        $fresh = $version->fresh();
        $this->assertSame($manager->id, $fresh->submitted_by);
        $this->assertNotNull($fresh->submitted_at);
        $this->assertSame(ProposalVersionLifecycle::Submitted, $fresh->lifecycle_status);
    }

    public function test_manager_reviewed_fields_remain_null_after_submit(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->completeDraft($proposal);

        app(ProposalVersionWorkflowService::class)->submit($version, $manager);

        $fresh = $version->fresh();
        $this->assertNull($fresh->manager_reviewed_by);
        $this->assertNull($fresh->manager_reviewed_at);
        $this->assertNull($fresh->manager_review_comment);
    }

    public function test_repeated_submit_fails_without_duplicate_state_change(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->completeDraft($proposal);

        app(ProposalVersionWorkflowService::class)->submit($version, $manager);
        $firstSubmittedAt = $version->fresh()->submitted_at;

        try {
            app(ProposalVersionWorkflowService::class)->submit($version, $manager);
            $this->fail('Expected a repeated Submit to be rejected.');
        } catch (LogicException) {
            // expected
        }

        $fresh = $version->fresh();
        $this->assertTrue($firstSubmittedAt->equalTo($fresh->submitted_at));
        $this->assertSame(1, DB::table('audit_events')
            ->where('entity_type', 'ProposalVersion')
            ->where('entity_id', $version->id)
            ->where('action', 'proposal_version_submitted')
            ->count());
    }

    public function test_structural_submit_validation_rejects_blank_customer_name(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->completeDraft($proposal);
        $version->forceFill(['customer_name_snapshot' => null])->save();

        $this->expectException(LogicException::class);
        app(ProposalVersionWorkflowService::class)->submit($version, $manager);
    }

    public function test_structural_submit_validation_rejects_zero_lines(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'version_number' => 1,
            'lifecycle_status' => ProposalVersionLifecycle::Draft,
            'customer_name_snapshot' => 'Acme Corp',
        ]);
        $proposal->forceFill(['current_version_id' => $version->id])->save();

        $this->expectException(LogicException::class);
        app(ProposalVersionWorkflowService::class)->submit($version, $manager);
    }

    public function test_structural_submit_validation_rejects_zero_quantity_line(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->completeDraft($proposal);
        ProposalVersionLine::query()->where('proposal_version_id', $version->id)->update(['quantity' => 0]);

        $this->expectException(LogicException::class);
        app(ProposalVersionWorkflowService::class)->submit($version, $manager);
    }

    public function test_structural_submit_validation_requires_billing_state_and_place_of_supply_when_tax_component_present(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->completeDraft($proposal);
        $line = ProposalVersionLine::query()->where('proposal_version_id', $version->id)->firstOrFail();

        ProposalVersionLineTaxComponent::create([
            'proposal_version_line_id' => $line->id,
            'component_type' => 'cgst',
            'rate' => 9,
            'amount' => 90,
        ]);

        $this->expectException(LogicException::class);
        app(ProposalVersionWorkflowService::class)->submit($version, $manager);
    }

    public function test_submit_succeeds_with_tax_component_once_billing_state_and_place_of_supply_are_set(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->completeDraft($proposal);
        $version->forceFill([
            'billing_state_snapshot' => 'Tamil Nadu',
            'place_of_supply_snapshot' => 'Tamil Nadu',
        ])->save();
        $line = ProposalVersionLine::query()->where('proposal_version_id', $version->id)->firstOrFail();

        ProposalVersionLineTaxComponent::create([
            'proposal_version_line_id' => $line->id,
            'component_type' => 'cgst',
            'rate' => 9,
            'amount' => 90,
        ]);

        app(ProposalVersionWorkflowService::class)->submit($version->fresh(), $manager);

        $this->assertSame(ProposalVersionLifecycle::Submitted, $version->fresh()->lifecycle_status);
    }

    public function test_submit_writes_a_correctly_shaped_audit_event(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->completeDraft($proposal);

        app(ProposalVersionWorkflowService::class)->submit($version, $manager);

        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'ProposalVersion',
            'entity_id' => $version->id,
            'action' => 'proposal_version_submitted',
            'organization_id' => $version->fresh()->organization_id,
        ]);
    }

    public function test_submit_never_touches_parent_proposal_outcome_or_winning_version(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->completeDraft($proposal);

        app(ProposalVersionWorkflowService::class)->submit($version, $manager);

        $fresh = $proposal->fresh();
        $this->assertNull($fresh->outcome);
        $this->assertNull($fresh->winning_version_id);
    }
}
