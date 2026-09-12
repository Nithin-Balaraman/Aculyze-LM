<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Enums\ProposalOutcome;
use App\Enums\ProposalStage;
use App\Enums\ProposalVersionLifecycle;
use App\Enums\UserRole;
use App\Filament\Pages\PipelineBoard;
use App\Filament\Resources\ProposalResource;
use App\Filament\Resources\ProposalResource\Pages\ListProposals;
use App\Filament\Resources\ProposalResource\Pages\ManageCommercialVersion;
use App\Filament\Resources\ProposalResource\Pages\ViewProposal;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\Prospect;
use App\Models\User;
use App\Services\ProposalCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pre-deployment items F6 (clarify the two coexisting status systems) and
 * F5 (Commercial Version shortcut from the Proposal list).
 *
 * Both are clarity/navigation only: Proposal.outcome logic is untouched,
 * winning_version_id is untouched, and no Version state becomes editable
 * from any Proposal screen. Written while PHASE4_OUTCOME_CUTOVER_GATE was
 * still OPEN; the gate itself closed in Phase 4A-3.5 (see
 * PHASE4_OUTCOME_CUTOVER_GATE.md) — the "Proposal Outcome" vs "Commercial
 * Version Status" labeling clarity these tests exercise remains valid and
 * unaffected by that closure.
 */
class ProposalStatusClarityAndShortcutTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{admin: User, employee: User} */
    private function people(): array
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create(['role' => UserRole::Employee, 'manager_id' => null]);

        return compact('admin', 'employee');
    }

    private function proposalWithV1(User $employee): Proposal
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => LeadStage::Validated,
            'temperature' => 'hot',
            'notes' => 'Ready for Proposal.',
        ]);

        return app(ProposalCreationService::class)->createForLead($lead, [
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => ProposalStage::BeingPrepared,
        ]);
    }

    // -----------------------------------------------------------------
    // F6 — the two status systems are named on every screen
    // -----------------------------------------------------------------

    public function test_the_proposal_list_names_both_status_systems(): void
    {
        ['admin' => $admin, 'employee' => $employee] = $this->people();
        $this->proposalWithV1($employee);

        $this->actingAs($admin);

        Livewire::test(ListProposals::class)
            ->assertSuccessful()
            ->assertSee('Proposal Stage')
            ->assertSee('Proposal Outcome')
            ->assertSee('Commercial Version Status');
    }

    public function test_the_list_shows_the_current_commercial_version_status(): void
    {
        ['admin' => $admin, 'employee' => $employee] = $this->people();
        $proposal = $this->proposalWithV1($employee);

        $this->actingAs($admin);

        Livewire::test(ListProposals::class)
            ->assertSuccessful()
            ->assertSee('Draft')
            ->assertSee('V'.$proposal->currentVersion->version_number);
    }

    public function test_a_proposal_with_no_commercial_version_is_reported_honestly(): void
    {
        ['admin' => $admin, 'employee' => $employee] = $this->people();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => LeadStage::Validated,
            'temperature' => 'hot',
            'notes' => 'Ready.',
        ]);
        // A legacy-shaped Proposal created without going through
        // ProposalCreationService, i.e. with no Version at all.
        Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => ProposalStage::BeingPrepared,
        ]);

        $this->actingAs($admin);

        Livewire::test(ListProposals::class)
            ->assertSuccessful()
            ->assertSee('No Version yet');
    }

    public function test_view_proposal_shows_all_three_states_side_by_side(): void
    {
        ['admin' => $admin, 'employee' => $employee] = $this->people();
        $proposal = $this->proposalWithV1($employee);

        $this->actingAs($admin);

        Livewire::test(ViewProposal::class, ['record' => $proposal->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Proposal Stage')
            ->assertSee('Proposal Outcome')
            ->assertSee('Commercial Version Status')
            ->assertSee('V1 — Draft');
    }

    public function test_the_commercial_version_status_on_a_proposal_screen_is_read_only(): void
    {
        // A Placeholder never dehydrates, so no Version state can be
        // written from the Proposal form even if the field is present.
        $contents = file_get_contents(app_path('Filament/Resources/ProposalResource.php'));

        $this->assertStringContainsString("Placeholder::make('commercial_version_status')", $contents);
        $this->assertStringNotContainsString("Select::make('lifecycle_status')", $contents);
        $this->assertStringNotContainsString("make('current_version_id')", $contents);
        $this->assertStringNotContainsString("make('winning_version_id')", $contents);
    }

    public function test_the_commercial_version_page_keeps_its_specific_label(): void
    {
        ['admin' => $admin, 'employee' => $employee] = $this->people();
        $proposal = $this->proposalWithV1($employee);

        $this->actingAs($admin);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Commercial Version Status');
    }

    public function test_the_pipeline_board_labels_the_version_status_without_changing_grouping(): void
    {
        ['admin' => $admin, 'employee' => $employee] = $this->people();
        $proposal = $this->proposalWithV1($employee);

        $this->actingAs($admin);

        Livewire::test(PipelineBoard::class)
            ->assertSuccessful()
            // The indicator is present...
            ->assertSee('CV · V1 DRAFT');

        // ...and the board still groups this Proposal by its LEGACY stage,
        // exactly as before — the commercial Version status is a label, not
        // a lane concept.
        $this->assertSame(ProposalStage::BeingPrepared, $proposal->fresh()->stage);
    }

    public function test_neither_change_touches_outcome_or_the_cutover_gate(): void
    {
        ['admin' => $admin, 'employee' => $employee] = $this->people();
        $proposal = $this->proposalWithV1($employee);

        $this->actingAs($admin);

        Livewire::test(ListProposals::class)->assertSuccessful();
        Livewire::test(ViewProposal::class, ['record' => $proposal->getRouteKey()])->assertSuccessful();

        $fresh = $proposal->fresh();
        $this->assertNull($fresh->outcome);
        $this->assertNull($fresh->winning_version_id);
        $this->assertSame(ProposalStage::BeingPrepared, $fresh->stage);
    }

    /**
     * Phase 4A-3.5 cutover: PHASE4_OUTCOME_CUTOVER_GATE is now CLOSED, and
     * Won can no longer exist without a real winning_version_id (DB CHECK).
     * What F6 still demonstrates post-cutover: the Proposal Outcome and the
     * commercial Version's OWN lifecycle status are two genuinely distinct
     * systems that can legitimately disagree in time — a Proposal can be
     * Won (via a real Accepted response against some earlier Sent Version)
     * while ITS CURRENT Version is still an unrelated, later Draft — and
     * both remain visible side by side rather than conflated.
     */
    public function test_a_won_outcome_and_a_different_current_commercial_version_status_coexist_visibly(): void
    {
        ['admin' => $admin, 'employee' => $employee] = $this->people();
        $proposal = $this->proposalWithV1($employee);
        $winningVersion = ProposalVersion::factory()->create(['proposal_id' => $proposal->id, 'version_number' => 2, 'lifecycle_status' => ProposalVersionLifecycle::Sent]);
        $proposal->forceFill(['outcome' => ProposalOutcome::Won, 'winning_version_id' => $winningVersion->id, 'notes' => 'Signed against V2, real Accepted response.'])->save();

        $this->actingAs($admin);

        Livewire::test(ViewProposal::class, ['record' => $proposal->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Proposal Outcome')
            ->assertSee('Commercial Version Status')
            ->assertSee('V1 — Draft');

        $this->assertSame($winningVersion->id, $proposal->fresh()->winning_version_id);
    }

    // -----------------------------------------------------------------
    // F5 — Commercial Version shortcut from the Proposal list
    // -----------------------------------------------------------------

    public function test_the_list_row_offers_a_direct_commercial_version_action(): void
    {
        ['admin' => $admin, 'employee' => $employee] = $this->people();
        $proposal = $this->proposalWithV1($employee);

        $this->actingAs($admin);

        Livewire::test(ListProposals::class)
            ->assertTableActionExists('commercialVersion')
            ->assertTableActionHasUrl('commercialVersion', ProposalResource::getUrl('commercial', ['record' => $proposal]), record: $proposal);
    }

    public function test_the_shortcut_targets_the_same_manage_commercial_version_page(): void
    {
        ['admin' => $admin, 'employee' => $employee] = $this->people();
        $proposal = $this->proposalWithV1($employee);

        $this->actingAs($admin);

        $this->get(ProposalResource::getUrl('commercial', ['record' => $proposal]))
            ->assertSuccessful()
            ->assertSee('Commercial Version');
    }

    public function test_the_shortcut_creates_and_mutates_nothing(): void
    {
        ['admin' => $admin, 'employee' => $employee] = $this->people();
        $proposal = $this->proposalWithV1($employee);
        $versionCount = $proposal->versions()->count();
        $currentVersionId = $proposal->current_version_id;
        $updatedAt = $proposal->currentVersion->updated_at;

        $this->actingAs($admin);

        Livewire::test(ListProposals::class)->assertTableActionExists('commercialVersion');

        $fresh = $proposal->fresh();
        $this->assertSame($versionCount, $fresh->versions()->count());
        $this->assertSame($currentVersionId, $fresh->current_version_id);
        $this->assertTrue($updatedAt->equalTo($fresh->currentVersion->updated_at));
    }

    public function test_the_shortcut_respects_proposal_visibility(): void
    {
        // An Employee only ever sees their own Proposals, so a row action on
        // someone else's Proposal cannot exist for them in the first place.
        ['employee' => $employee] = $this->people();
        $otherEmployee = User::factory()->create(['role' => UserRole::Employee]);
        $theirs = $this->proposalWithV1($otherEmployee);

        $this->actingAs($employee);

        Livewire::test(ListProposals::class)
            ->assertSuccessful()
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_no_global_proposal_version_navigation_item_was_added(): void
    {
        // Locked product decision: ProposalVersion stays contextual under
        // Proposal — the shortcut must not become a sidebar entry.
        $resources = glob(app_path('Filament/Resources/*.php'));
        $names = array_map(fn (string $path) => basename($path, '.php'), $resources);

        $this->assertNotContains('ProposalVersionResource', $names);
    }
}
