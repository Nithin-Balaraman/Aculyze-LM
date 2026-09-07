<?php

namespace Tests\Feature;

use App\Enums\ProposalVersionLifecycle;
use App\Enums\UserRole;
use App\Filament\Resources\ProposalResource\Pages\ManageCommercialVersion;
use App\Filament\Resources\ProposalResource\Pages\ViewCommercialVersion;
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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 4A-2.5 manual-smoke bug fix: Version History must render REAL
 * persisted values (it previously rendered blank rows — see
 * ManageCommercialVersion::infolist()'s own docblock for the exact
 * Filament state-resolution cause), and every Version, current or
 * historical, must be openable read-only so the frozen commercial record
 * is actually inspectable from the application rather than only from the
 * database.
 */
class ProposalVersionHistoryViewTest extends TestCase
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

    /** A Draft carrying real, distinctive commercial content, ready to Submit. */
    private function populatedDraft(Proposal $proposal, string $customerName = 'Historic Customer Ltd'): ProposalVersion
    {
        $version = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'version_number' => 1,
            'customer_name_snapshot' => $customerName,
            'customer_gstin_snapshot' => '33AAAAA0000A1Z5',
            'billing_state_snapshot' => 'Tamil Nadu',
            'place_of_supply_snapshot' => 'Tamil Nadu',
            'payment_terms' => 'Net 30 from invoice',
            'validity_terms' => 'Valid for 30 days',
        ]);
        $proposal->forceFill(['current_version_id' => $version->id])->save();

        $line = ProposalVersionLine::create([
            'proposal_version_id' => $version->id,
            'line_number' => 1,
            'item_name' => 'Frozen Widget Assembly',
            'quantity' => 2,
            'unit_price' => 500,
        ]);

        ProposalVersionLineTaxComponent::create([
            'proposal_version_line_id' => $line->id,
            'component_type' => 'igst',
            'rate' => 18,
            'amount' => 0,
        ]);

        return $version->fresh();
    }

    // -----------------------------------------------------------------
    // VERSION HISTORY DATA (the reported blank-rows bug)
    // -----------------------------------------------------------------

    public function test_version_history_renders_real_persisted_values_for_every_version(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);

        $v1 = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'version_number' => 1,
            'lifecycle_status' => ProposalVersionLifecycle::Approved,
            'grand_total' => '4321.99',
        ]);
        $v2 = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'version_number' => 2,
            'grand_total' => '8765.44',
        ]);
        $proposal->forceFill(['current_version_id' => $v2->id])->save();
        $v1->forceFill(['superseded_by_version_id' => $v2->id, 'superseded_at' => now()])->save();

        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertSuccessful()
            // Both Versions' own persisted grand totals must actually render —
            // this is precisely what was blank before the fix.
            ->assertSee('4,321.99')
            ->assertSee('8,765.44')
            ->assertSee('V1')
            ->assertSee('V2');
    }

    public function test_version_history_renders_workflow_evidence_values(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->populatedDraft($proposal);

        app(ProposalVersionWorkflowService::class)->submit($version, $manager);
        app(ProposalVersionWorkflowService::class)->approve($version->fresh(), $seniorManager, 'Approved in test.');

        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertSuccessful()
            ->assertSee($manager->name)
            ->assertSee($seniorManager->name)
            ->assertSee('Approved in test.');
    }

    public function test_version_history_is_ordered_newest_version_first(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);

        ProposalVersion::factory()->create(['proposal_id' => $proposal->id, 'version_number' => 1, 'lifecycle_status' => ProposalVersionLifecycle::Sent, 'grand_total' => '111.11']);
        ProposalVersion::factory()->create(['proposal_id' => $proposal->id, 'version_number' => 2, 'lifecycle_status' => ProposalVersionLifecycle::Sent, 'grand_total' => '222.22']);
        $v3 = ProposalVersion::factory()->create(['proposal_id' => $proposal->id, 'version_number' => 3, 'grand_total' => '333.33']);
        $proposal->forceFill(['current_version_id' => $v3->id])->save();

        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertSuccessful()
            // Newest first: V3's total appears before V2's, which appears before V1's.
            ->assertSeeInOrder(['333.33', '222.22', '111.11']);
    }

    public function test_missing_legacy_evidence_is_shown_neutrally_and_never_fabricated(): void
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
            ->assertSuccessful()
            ->assertSee('Legacy');

        $this->assertNull($legacySent->submitted_by);
        $this->assertNull($legacySent->approved_by);
        $this->assertNull($legacySent->approved_at);
    }

    // -----------------------------------------------------------------
    // SECTION 7 — APPROVED -> CREATE REVISION LIFECYCLE TRUTH, VIA THE UI
    // -----------------------------------------------------------------

    public function test_approved_version_remains_inspectable_and_approved_after_create_revision(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $v1 = $this->populatedDraft($proposal);

        app(ProposalVersionWorkflowService::class)->submit($v1, $manager);
        app(ProposalVersionWorkflowService::class)->approve($v1->fresh(), $seniorManager);

        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->call('createRevisionAction')
            ->assertHasNoErrors();

        $v2 = $proposal->fresh()->currentVersion;
        $this->assertSame(2, $v2->version_number);
        $this->assertSame(ProposalVersionLifecycle::Draft, $v2->lifecycle_status);

        // The database facts the manual test could not verify from the UI...
        $v1 = $v1->fresh();
        $this->assertSame(ProposalVersionLifecycle::Approved, $v1->lifecycle_status);
        $this->assertSame($v2->id, $v1->superseded_by_version_id);

        // ...are now actually inspectable THROUGH the UI layer.
        Livewire::test(ViewCommercialVersion::class, ['record' => $proposal->getRouteKey(), 'version' => $v1->id])
            ->assertSuccessful()
            ->assertSee('V1')
            ->assertSee('Approved')
            ->assertSee('Historical')
            ->assertSee('Historic Customer Ltd')
            ->assertSee('Frozen Widget Assembly')
            ->assertSee('1,180.00')   // V1's own frozen line total / grand total
            ->assertSee('V2');        // Superseded By
    }

    // -----------------------------------------------------------------
    // SECTION 8 — RETURN FOR REVISION HISTORY, VIA THE UI
    // -----------------------------------------------------------------

    public function test_returned_version_remains_inspectable_with_its_reason_and_frozen_content(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $v1 = $this->populatedDraft($proposal);

        app(ProposalVersionWorkflowService::class)->submit($v1, $manager);

        $this->actingAs($seniorManager);
        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->callAction('returnVersion', data: ['return_reason' => 'Please add the revised GSTIN.'])
            ->assertHasNoActionErrors();

        $v2 = $proposal->fresh()->currentVersion;
        $this->assertSame(2, $v2->version_number);
        $this->assertSame(ProposalVersionLifecycle::Draft, $v2->lifecycle_status);
        $this->assertSame(ProposalVersionLifecycle::ReturnedForRevision, $v1->fresh()->lifecycle_status);

        Livewire::test(ViewCommercialVersion::class, ['record' => $proposal->getRouteKey(), 'version' => $v1->id])
            ->assertSuccessful()
            ->assertSee('Returned for Revision')
            ->assertSee('Please add the revised GSTIN.')
            ->assertSee('Historic Customer Ltd')
            ->assertSee('Frozen Widget Assembly')
            ->assertSee('V2');

        // The new Draft remains separately editable as the current Version.
        $this->actingAs($manager);
        $component = Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()]);
        $this->assertTrue($component->instance()->isDraftEditable());
        $this->assertSame($v2->id, $component->instance()->currentVersion->id);
    }

    // -----------------------------------------------------------------
    // AUTHORIZATION
    // -----------------------------------------------------------------

    public function test_employee_who_may_view_the_proposal_can_view_a_historical_version_read_only(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $v1 = $this->populatedDraft($proposal);
        app(ProposalVersionWorkflowService::class)->submit($v1, $manager);
        app(ProposalVersionWorkflowService::class)->approve($v1->fresh(), $seniorManager);

        $this->actingAs($employee);

        Livewire::test(ViewCommercialVersion::class, ['record' => $proposal->getRouteKey(), 'version' => $v1->id])
            ->assertSuccessful()
            ->assertSee('Historic Customer Ltd');
    }

    public function test_manager_can_view_a_historical_version(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $v1 = $this->populatedDraft($proposal);

        $this->actingAs($manager);

        Livewire::test(ViewCommercialVersion::class, ['record' => $proposal->getRouteKey(), 'version' => $v1->id])
            ->assertSuccessful();
    }

    public function test_senior_manager_can_view_a_historical_version_org_wide(): void
    {
        ['employee' => $employee, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $v1 = $this->populatedDraft($proposal);

        $this->actingAs($seniorManager);

        Livewire::test(ViewCommercialVersion::class, ['record' => $proposal->getRouteKey(), 'version' => $v1->id])
            ->assertSuccessful();
    }

    public function test_a_version_belonging_to_another_proposal_cannot_be_opened_by_forging_the_version_id(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $this->populatedDraft($proposal);

        // A second Proposal in the SAME organization, with its own Version.
        $otherEmployee = User::factory()->create(['role' => UserRole::Employee, 'manager_id' => $manager->id]);
        $otherProposal = $this->proposalFor($otherEmployee);
        $foreignVersion = ProposalVersion::factory()->create(['proposal_id' => $otherProposal->id, 'version_number' => 1]);
        $otherProposal->forceFill(['current_version_id' => $foreignVersion->id])->save();

        $this->actingAs($manager);

        $this->expectException(ModelNotFoundException::class);

        Livewire::test(ViewCommercialVersion::class, [
            'record' => $proposal->getRouteKey(),
            'version' => $foreignVersion->id,
        ]);
    }

    public function test_a_cross_organization_version_cannot_be_opened(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $this->populatedDraft($proposal);

        $otherOrg = Organization::factory()->create();
        $foreignVersionId = Tenancy::runAs($otherOrg->id, function () use ($otherOrg) {
            $otherUser = User::factory()->create(['organization_id' => $otherOrg->id]);
            $otherProposal = $this->proposalFor($otherUser);
            $version = ProposalVersion::factory()->create(['proposal_id' => $otherProposal->id, 'version_number' => 1]);

            return $version->id;
        });

        $this->actingAs($manager);

        $this->expectException(ModelNotFoundException::class);

        Livewire::test(ViewCommercialVersion::class, [
            'record' => $proposal->getRouteKey(),
            'version' => $foreignVersionId,
        ]);
    }

    public function test_the_historical_version_view_exposes_no_mutating_actions(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $v1 = $this->populatedDraft($proposal);
        app(ProposalVersionWorkflowService::class)->submit($v1, $manager);
        app(ProposalVersionWorkflowService::class)->approve($v1->fresh(), $seniorManager);

        $this->actingAs($seniorManager);

        Livewire::test(ViewCommercialVersion::class, ['record' => $proposal->getRouteKey(), 'version' => $v1->id])
            ->assertSuccessful()
            ->assertActionDoesNotExist('saveDraft')
            ->assertActionDoesNotExist('submitVersion')
            ->assertActionDoesNotExist('approveVersion')
            ->assertActionDoesNotExist('returnVersion')
            ->assertActionDoesNotExist('createRevision')
            ->assertActionDoesNotExist('edit');
    }

    public function test_the_historical_version_view_shows_that_versions_own_data_not_the_current_versions(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $v1 = $this->populatedDraft($proposal, 'Original V1 Customer');

        app(ProposalVersionWorkflowService::class)->submit($v1, $manager);
        app(ProposalVersionWorkflowService::class)->approve($v1->fresh(), $seniorManager);

        $this->actingAs($manager);
        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->call('createRevisionAction');

        $v2 = $proposal->fresh()->currentVersion;

        // Change the CURRENT Draft's customer name; V1's own view must not follow it.
        $v2->forceFill(['customer_name_snapshot' => 'Renamed On V2 Only'])->save();

        Livewire::test(ViewCommercialVersion::class, ['record' => $proposal->getRouteKey(), 'version' => $v1->id])
            ->assertSuccessful()
            ->assertSee('Original V1 Customer')
            ->assertDontSee('Renamed On V2 Only');
    }
}
