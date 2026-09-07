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
 * Phase 4A-2.5: Submit/Approve/Return/Create Revision through
 * ManageCommercialVersion's own header actions — each simply forwards to
 * ProposalVersionWorkflowService, whose own extensive test suite already
 * covers every business rule; these tests confirm the UI wiring itself
 * (visibility, data passed through, state refresh) and that a forged
 * Livewire call cannot bypass server-side authorization.
 */
class ManageCommercialVersionWorkflowActionsTest extends TestCase
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

    private function completeDraft(Proposal $proposal): ProposalVersion
    {
        $version = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'version_number' => 1,
            'customer_name_snapshot' => 'Acme Corp',
            'payment_terms' => 'Net 30',
            'validity_terms' => '30 days',
        ]);
        $proposal->forceFill(['current_version_id' => $version->id])->save();

        ProposalVersionLine::create([
            'proposal_version_id' => $version->id,
            'line_number' => 1,
            'item_name' => 'Widget',
            'quantity' => 2,
            'unit_price' => 500,
        ]);

        return $version->fresh();
    }

    private function submittedVersion(Proposal $proposal, User $submitter): ProposalVersion
    {
        $version = $this->completeDraft($proposal);
        app(ProposalVersionWorkflowService::class)->submit($version, $submitter);

        return $version->fresh();
    }

    private function approvedVersion(Proposal $proposal, User $submitter, User $approver): ProposalVersion
    {
        $version = $this->submittedVersion($proposal, $submitter);
        app(ProposalVersionWorkflowService::class)->approve($version, $approver);

        return $version->fresh();
    }

    // -----------------------------------------------------------------
    // D. SUBMIT
    // -----------------------------------------------------------------

    public function test_valid_draft_can_submit_from_ui(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $this->completeDraft($proposal);
        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->call('submitVersion')
            ->assertHasNoErrors();

        $this->assertSame(ProposalVersionLifecycle::Submitted, $proposal->fresh()->currentVersion->lifecycle_status);
    }

    public function test_incomplete_draft_surfaces_a_useful_failure(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        // A bare Draft with no lines/customer name at all — Submit's own
        // structural check must reject this, and the page must not crash.
        $version = ProposalVersion::factory()->create(['proposal_id' => $proposal->id, 'version_number' => 1, 'customer_name_snapshot' => null]);
        $proposal->forceFill(['current_version_id' => $version->id])->save();
        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->call('submitVersion')
            ->assertNotified();

        $this->assertSame(ProposalVersionLifecycle::Draft, $proposal->fresh()->currentVersion->lifecycle_status);
    }

    public function test_successful_submit_refreshes_lifecycle_display(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $this->completeDraft($proposal);
        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->call('submitVersion')
            ->assertSee('Submitted');
    }

    public function test_edit_action_becomes_unavailable_after_submit(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $this->completeDraft($proposal);
        $this->actingAs($manager);

        $component = Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()]);
        $this->assertTrue($component->instance()->isDraftEditable());

        $component->call('submitVersion');

        $this->assertFalse($component->instance()->isDraftEditable());
        $component->assertActionHidden('saveDraft');
    }

    public function test_manager_reviewed_fields_remain_untouched_after_submit(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $this->completeDraft($proposal);
        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->call('submitVersion');

        $version = $proposal->fresh()->currentVersion;
        $this->assertNull($version->manager_reviewed_by);
        $this->assertNull($version->manager_reviewed_at);
        $this->assertNull($version->manager_review_comment);
    }

    // -----------------------------------------------------------------
    // E. APPROVE
    // -----------------------------------------------------------------

    public function test_senior_manager_can_approve_submitted_version_from_ui(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $this->submittedVersion($proposal, $manager);
        $this->actingAs($seniorManager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->callAction('approveVersion')
            ->assertHasNoActionErrors();

        $this->assertSame(ProposalVersionLifecycle::Approved, $proposal->fresh()->currentVersion->lifecycle_status);
    }

    public function test_optional_approval_comment_persists(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $this->submittedVersion($proposal, $manager);
        $this->actingAs($seniorManager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->callAction('approveVersion', data: ['approval_comment' => 'Looks good, proceed.'])
            ->assertHasNoActionErrors();

        $this->assertSame('Looks good, proceed.', $proposal->fresh()->currentVersion->approval_comment);
    }

    public function test_submitter_cannot_self_approve(): void
    {
        $employee = User::factory()->create(['role' => UserRole::Employee]);
        $seniorManagerWhoSubmits = User::factory()->admin()->create(['organization_id' => $employee->organization_id]);
        $proposal = $this->proposalFor($employee);
        $this->submittedVersion($proposal, $seniorManagerWhoSubmits);
        $this->actingAs($seniorManagerWhoSubmits);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertActionHidden('approveVersion');
    }

    /** Authorization is enforced server-side by the action's own visible() check AND the service itself — not merely hidden client-side. */
    public function test_manager_cannot_invoke_approve_by_a_forged_call(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $this->submittedVersion($proposal, $manager);
        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->call('approveVersion', null)
            ->assertNotified();

        $this->assertSame(ProposalVersionLifecycle::Submitted, $proposal->fresh()->currentVersion->lifecycle_status);
    }

    // -----------------------------------------------------------------
    // F. RETURN
    // -----------------------------------------------------------------

    public function test_senior_manager_sees_return_on_submitted(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $this->submittedVersion($proposal, $manager);
        $this->actingAs($seniorManager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertActionVisible('returnVersion');
    }

    public function test_blank_return_reason_is_rejected(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $this->submittedVersion($proposal, $manager);
        $this->actingAs($seniorManager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->callAction('returnVersion', data: ['return_reason' => ''])
            ->assertHasActionErrors(['return_reason' => 'required']);

        $this->assertSame(ProposalVersionLifecycle::Submitted, $proposal->fresh()->currentVersion->lifecycle_status);
    }

    public function test_valid_return_creates_a_new_draft(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $this->submittedVersion($proposal, $manager);
        $this->actingAs($seniorManager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->callAction('returnVersion', data: ['return_reason' => 'Please add GSTIN.'])
            ->assertHasNoActionErrors();

        $this->assertSame(2, ProposalVersion::where('proposal_id', $proposal->id)->count());
        $newDraft = $proposal->fresh()->currentVersion;
        $this->assertSame(2, $newDraft->version_number);
        $this->assertSame(ProposalVersionLifecycle::Draft, $newDraft->lifecycle_status);
    }

    public function test_ui_reflects_new_current_draft_after_return(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $this->submittedVersion($proposal, $manager);
        $this->actingAs($seniorManager);

        $component = Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->callAction('returnVersion', data: ['return_reason' => 'Please add GSTIN.']);

        $this->assertSame(2, $component->instance()->currentVersion->version_number);
    }

    public function test_old_version_remains_visible_read_only_in_history_after_return(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $originalVersion = $this->submittedVersion($proposal, $manager);
        $this->actingAs($seniorManager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->callAction('returnVersion', data: ['return_reason' => 'Please add GSTIN.']);

        $this->assertSame(ProposalVersionLifecycle::ReturnedForRevision, $originalVersion->fresh()->lifecycle_status);
    }

    // -----------------------------------------------------------------
    // G. CREATE REVISION
    // -----------------------------------------------------------------

    public function test_manager_can_create_revision_from_approved(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $this->approvedVersion($proposal, $manager, $seniorManager);
        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->call('createRevisionAction')
            ->assertHasNoErrors();

        $this->assertSame(2, $proposal->fresh()->currentVersion->version_number);
    }

    public function test_manager_can_create_revision_from_sent(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->approvedVersion($proposal, $manager, $seniorManager);
        $version->forceFill(['lifecycle_status' => ProposalVersionLifecycle::Sent, 'sent_at' => now()])->save();
        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->call('createRevisionAction')
            ->assertHasNoErrors();

        $this->assertSame(2, $proposal->fresh()->currentVersion->version_number);
    }

    public function test_senior_manager_can_create_revision(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $this->approvedVersion($proposal, $manager, $seniorManager);
        $this->actingAs($seniorManager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertActionVisible('createRevision');
    }

    public function test_employee_cannot_forge_create_revision(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $this->approvedVersion($proposal, $manager, $seniorManager);
        $this->actingAs($employee);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->call('createRevisionAction')
            ->assertNotified();

        $this->assertSame(1, ProposalVersion::where('proposal_id', $proposal->id)->count());
    }

    public function test_new_draft_becomes_current_after_create_revision(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $this->approvedVersion($proposal, $manager, $seniorManager);
        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->call('createRevisionAction');

        $newDraft = $proposal->fresh()->currentVersion;
        $this->assertSame(ProposalVersionLifecycle::Draft, $newDraft->lifecycle_status);
        $this->assertSame($newDraft->id, $proposal->fresh()->current_version_id);
    }

    public function test_old_lifecycle_remains_unchanged_after_create_revision(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $original = $this->approvedVersion($proposal, $manager, $seniorManager);
        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->call('createRevisionAction');

        $this->assertSame(ProposalVersionLifecycle::Approved, $original->fresh()->lifecycle_status);
    }

    public function test_legacy_sent_can_use_create_revision(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $legacySent = ProposalVersion::factory()->legacyBackfill()->create([
            'proposal_id' => $proposal->id,
            'version_number' => 1,
            'lifecycle_status' => ProposalVersionLifecycle::Sent,
            'customer_name_snapshot' => null,
            'payment_terms' => null,
            'validity_terms' => null,
            'scope_notes' => null,
            'subtotal' => null,
            'total_discount' => null,
            'tax_total' => null,
            'grand_total' => null,
        ]);
        $proposal->forceFill(['current_version_id' => $legacySent->id])->save();
        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->call('createRevisionAction')
            ->assertHasNoErrors();

        $newDraft = $proposal->fresh()->currentVersion;
        $this->assertSame(2, $newDraft->version_number);
        $this->assertSame(ProposalVersionLifecycle::Draft, $newDraft->lifecycle_status);
    }
}
