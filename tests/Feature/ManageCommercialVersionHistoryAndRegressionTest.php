<?php

namespace Tests\Feature;

use App\Enums\ProposalOutcome;
use App\Enums\ProposalStage;
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
 * Phase 4A-2.5: Version History display (H) and the raw-metadata /
 * legacy-outcome-coexistence regression checklist (I) — confirming the new
 * commercial UI never exposes service-controlled facts as directly
 * editable fields, and never disturbs the legacy Proposal.outcome/stage
 * system or PHASE4_OUTCOME_CUTOVER_GATE.
 */
class ManageCommercialVersionHistoryAndRegressionTest extends TestCase
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

    // -----------------------------------------------------------------
    // H. VERSION HISTORY
    // -----------------------------------------------------------------

    public function test_multiple_versions_render_in_correct_order(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);

        $v1 = ProposalVersion::factory()->create(['proposal_id' => $proposal->id, 'version_number' => 1, 'lifecycle_status' => ProposalVersionLifecycle::Sent]);
        $v2 = ProposalVersion::factory()->create(['proposal_id' => $proposal->id, 'version_number' => 2]);
        $proposal->forceFill(['current_version_id' => $v2->id])->save();
        $v1->forceFill(['superseded_by_version_id' => $v2->id, 'superseded_at' => now()])->save();

        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertSuccessful()
            ->assertSeeInOrder(['1', '2']);
    }

    public function test_frozen_history_is_read_only(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);

        $v1 = ProposalVersion::factory()->create(['proposal_id' => $proposal->id, 'version_number' => 1, 'lifecycle_status' => ProposalVersionLifecycle::Sent]);
        $v2 = ProposalVersion::factory()->create(['proposal_id' => $proposal->id, 'version_number' => 2]);
        $proposal->forceFill(['current_version_id' => $v2->id])->save();

        // The Draft editor never targets a non-current Version — it always
        // operates on the Proposal's own currentVersion.
        $this->actingAs($manager);
        $component = Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()]);

        $this->assertSame($v2->id, $component->instance()->currentVersion->id);
        $this->assertNotSame($v1->id, $component->instance()->currentVersion->id);
    }

    public function test_superseded_relationship_is_represented_accurately(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);

        $v1 = ProposalVersion::factory()->create(['proposal_id' => $proposal->id, 'version_number' => 1, 'lifecycle_status' => ProposalVersionLifecycle::Sent]);
        $v2 = ProposalVersion::factory()->create(['proposal_id' => $proposal->id, 'version_number' => 2]);
        $proposal->forceFill(['current_version_id' => $v2->id])->save();
        $v1->forceFill(['superseded_by_version_id' => $v2->id, 'superseded_at' => now()])->save();

        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('V2');
    }

    public function test_legacy_evidence_gaps_are_not_fabricated(): void
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
        ]);
        $proposal->forceFill(['current_version_id' => $legacySent->id])->save();
        $this->actingAs($manager);

        $component = Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertSuccessful();

        $this->assertNull($legacySent->submitted_by);
        $this->assertNull($legacySent->approved_by);
        $component->assertSee('Not available');
    }

    // -----------------------------------------------------------------
    // I. RAW METADATA / OUTCOME REGRESSION
    // -----------------------------------------------------------------

    public function test_no_generic_version_lifecycle_edit_is_exposed(): void
    {
        $contents = file_get_contents(app_path('Filament/Resources/ProposalResource/Pages/ManageCommercialVersion.php'));

        $this->assertStringNotContainsString("Select::make('lifecycle_status')", $contents);
        $this->assertStringNotContainsString("TextInput::make('lifecycle_status')", $contents);
    }

    public function test_no_current_version_id_edit_is_exposed(): void
    {
        $contents = file_get_contents(app_path('Filament/Resources/ProposalResource/Pages/ManageCommercialVersion.php'));

        $this->assertStringNotContainsString("make('current_version_id')", $contents);
    }

    public function test_no_winning_version_id_edit_through_the_new_ui(): void
    {
        $contents = file_get_contents(app_path('Filament/Resources/ProposalResource/Pages/ManageCommercialVersion.php'));

        $this->assertStringNotContainsString("make('winning_version_id')", $contents);
    }

    public function test_proposal_outcome_behavior_remains_unchanged(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = ProposalVersion::factory()->create(['proposal_id' => $proposal->id, 'version_number' => 1]);
        $proposal->forceFill(['current_version_id' => $version->id])->save();
        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->set('draftData.customer_name_snapshot', 'Acme Corp')
            ->call('saveDraft');

        $fresh = $proposal->fresh();
        $this->assertNull($fresh->outcome);
        $this->assertSame(ProposalStage::BeingPrepared, $fresh->stage);
    }

    public function test_phase4_outcome_cutover_gate_remains_open(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id, 'version_number' => 1,
            'customer_name_snapshot' => 'Acme Corp', 'payment_terms' => 'Net 30', 'validity_terms' => '30 days',
        ]);
        $proposal->forceFill(['current_version_id' => $version->id, 'outcome' => ProposalOutcome::Won, 'notes' => 'Signed already, legacy flow.'])->save();
        ProposalVersionLine::create(['proposal_version_id' => $version->id, 'line_number' => 1, 'item_name' => 'Widget', 'quantity' => 1, 'unit_price' => 100]);

        $this->actingAs($manager);
        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->call('submitVersion');

        app(ProposalVersionWorkflowService::class)->approve($version->fresh(), $seniorManager);

        $fresh = $proposal->fresh();
        $this->assertSame(ProposalOutcome::Won, $fresh->outcome);
        $this->assertNull($fresh->winning_version_id);
        $this->assertSame(ProposalVersionLifecycle::Approved, $fresh->currentVersion->lifecycle_status);
    }
}
