<?php

namespace Tests\Feature;

use App\Enums\ProposalVersionLifecycle;
use App\Enums\UserRole;
use App\Filament\Resources\ProposalResource\Pages\ManageCommercialVersion;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use App\Models\Prospect;
use App\Models\User;
use App\Services\ProposalVersionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 4A-2.5: role/action visibility on ManageCommercialVersion.
 * Visibility here is UX only — ProposalVersionPolicy/the domain services
 * remain the real authority (confirmed by
 * ManageCommercialVersionWorkflowActionsTest's own forged-call tests).
 */
class ManageCommercialVersionVisibilityTest extends TestCase
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
            'notes' => 'Ready for Proposal.',
        ]);

        return Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => 'being_prepared',
        ]);
    }

    private function draftVersion(Proposal $proposal): ProposalVersion
    {
        $version = ProposalVersion::factory()->create(['proposal_id' => $proposal->id, 'version_number' => 1]);
        $proposal->forceFill(['current_version_id' => $version->id])->save();

        return $version;
    }

    private function submittedVersion(Proposal $proposal, User $submitter): ProposalVersion
    {
        $version = $this->draftVersion($proposal);
        ProposalVersionLine::create([
            'proposal_version_id' => $version->id, 'line_number' => 1, 'item_name' => 'Widget', 'quantity' => 1, 'unit_price' => 100,
        ]);
        $version->forceFill(['customer_name_snapshot' => 'Acme Corp', 'payment_terms' => 'Net 30', 'validity_terms' => '30 days', 'currency_code' => 'INR'])->save();
        app(ProposalVersionWorkflowService::class)->submit($version->fresh(), $submitter);

        return $version->fresh();
    }

    private function approvedOrSentVersion(Proposal $proposal, User $submitter, User $approver, ProposalVersionLifecycle $target = ProposalVersionLifecycle::Approved): ProposalVersion
    {
        $version = $this->submittedVersion($proposal, $submitter);
        app(ProposalVersionWorkflowService::class)->approve($version, $approver);

        if ($target === ProposalVersionLifecycle::Sent) {
            $version->fresh()->forceFill(['lifecycle_status' => ProposalVersionLifecycle::Sent, 'sent_at' => now()])->save();
        }

        return $version->fresh();
    }

    // -----------------------------------------------------------------
    // EMPLOYEE
    // -----------------------------------------------------------------

    public function test_employee_can_view_proposal_commercial_summary(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $this->draftVersion($proposal);
        $this->actingAs($employee);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertSuccessful();
    }

    public function test_employee_cannot_see_edit_commercial_draft(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $this->draftVersion($proposal);
        $this->actingAs($employee);

        $component = Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()]);
        $this->assertFalse($component->instance()->isDraftEditable());
    }

    public function test_employee_cannot_submit(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $this->draftVersion($proposal);
        $this->actingAs($employee);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertActionHidden('submitVersion');
    }

    public function test_employee_cannot_approve(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $this->submittedVersion($proposal, $manager);
        $this->actingAs($employee);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertActionHidden('approveVersion');
    }

    public function test_employee_cannot_return(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $this->submittedVersion($proposal, $manager);
        $this->actingAs($employee);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertActionHidden('returnVersion');
    }

    public function test_employee_cannot_create_revision(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $this->approvedOrSentVersion($proposal, $manager, $seniorManager);
        $this->actingAs($employee);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertActionHidden('createRevision');
    }

    // -----------------------------------------------------------------
    // MANAGER
    // -----------------------------------------------------------------

    public function test_authorized_manager_sees_edit_on_draft(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $this->draftVersion($proposal);
        $this->actingAs($manager);

        $component = Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()]);
        $this->assertTrue($component->instance()->isDraftEditable());
    }

    public function test_authorized_manager_sees_submit_on_draft(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $this->draftVersion($proposal);
        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertActionVisible('submitVersion');
    }

    public function test_authorized_manager_cannot_approve(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $this->submittedVersion($proposal, $manager);
        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertActionHidden('approveVersion');
    }

    public function test_authorized_manager_cannot_return(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $this->submittedVersion($proposal, $manager);
        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertActionHidden('returnVersion');
    }

    public function test_authorized_manager_sees_create_revision_on_approved_or_sent(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $this->approvedOrSentVersion($proposal, $manager, $seniorManager);
        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertActionVisible('createRevision');
    }

    // -----------------------------------------------------------------
    // SENIOR MANAGER
    // -----------------------------------------------------------------

    public function test_senior_manager_sees_org_wide_actions(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $this->submittedVersion($proposal, $manager);
        $this->actingAs($seniorManager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertActionVisible('approveVersion')
            ->assertActionVisible('returnVersion');
    }

    public function test_submitter_senior_manager_does_not_get_a_valid_self_approve_capability(): void
    {
        $employee = User::factory()->create(['role' => UserRole::Employee]);
        $seniorManagerWhoSubmits = User::factory()->admin()->create(['organization_id' => $employee->organization_id]);
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersion($proposal, $seniorManagerWhoSubmits);
        $this->assertSame($seniorManagerWhoSubmits->id, $version->submitted_by);

        $this->actingAs($seniorManagerWhoSubmits);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertActionHidden('approveVersion');
    }
}
