<?php

namespace Tests\Feature;

use App\Enums\ProposalOutcome;
use App\Enums\ProposalStage;
use App\Enums\ProposalVersionLifecycle;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use App\Models\ProposalVersionLineTaxComponent;
use App\Models\Prospect;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 4A-1: domain-shape tests for the new ProposalVersion/
 * ProposalVersionLine/ProposalVersionLineTaxComponent tables — relationships,
 * organization isolation, and the draft-lock uniqueness database invariant
 * that this phase's migrations introduced. Backfill-specific behavior lives
 * in ProposalVersionBackfillTest instead.
 *
 * "Proposal.outcome = Won requires winning_version_id" is deliberately NOT
 * tested here as a DB-level rejection: that CHECK constraint was dropped
 * from 4A-1 (see 2026_09_05_090100_add_version_pointers_to_proposals_table's
 * own docblock) because it broke every existing way this app already marks
 * a Proposal Won, ahead of 4A-2's real Won/approval workflow. The positive
 * case below (winning_version_id set correctly) still applies unconditionally.
 */
class ProposalVersionModelTest extends TestCase
{
    use RefreshDatabase;

    private function proposal(User $owner, ProposalStage $stage = ProposalStage::BeingPrepared, ?ProposalOutcome $outcome = null): Proposal
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);

        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'stage' => 'validated',
            'temperature' => 'hot',
            'notes' => 'Validated in test fixture.',
        ]);

        return Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'stage' => $stage,
            'outcome' => $outcome,
            'notes' => $outcome !== null ? 'Outcome recorded in test fixture.' : null,
        ]);
    }

    public function test_proposal_versions_and_lines_and_tax_components_relate_correctly(): void
    {
        $owner = User::factory()->create();
        $proposal = $this->proposal($owner);

        $version = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'version_number' => 1,
        ]);

        $line = ProposalVersionLine::create([
            'proposal_version_id' => $version->id,
            'line_number' => 1,
            'item_name' => 'Widget',
            'quantity' => 2,
            'unit_price' => 500,
        ]);

        $taxComponent = ProposalVersionLineTaxComponent::create([
            'proposal_version_line_id' => $line->id,
            'component_type' => 'cgst',
            'rate' => 9,
            'amount' => 90,
        ]);

        // current_version_id is deliberately excluded from $fillable (a
        // system-managed pointer column) — forceFill() mirrors how the
        // backfill command and future workflow services must set it.
        $proposal->forceFill(['current_version_id' => $version->id])->save();

        $this->assertTrue($proposal->fresh()->versions->contains($version));
        $this->assertSame($version->id, $proposal->fresh()->currentVersion->id);
        $this->assertSame($proposal->id, $version->proposal->id);
        $this->assertTrue($version->lines->contains($line));
        $this->assertSame($version->id, $line->proposalVersion->id);
        $this->assertTrue($line->taxComponents->contains($taxComponent));
        $this->assertSame($line->id, $taxComponent->line->id);
    }

    /**
     * proposal_id is deliberately not a useful column for this scenario:
     * ProposalVersion::inheritedOrganizationId() derives organization_id
     * FROM proposal_id itself, so the two can never diverge — instead this
     * exercises one of the genuinely independent scoped relations
     * (manager_reviewed_by), mirroring how CrossOrganizationRelationshipInjectionTest
     * picks a relation independent of the model's own inheritance source.
     */
    public function test_creating_a_proposal_version_referencing_another_organizations_user_is_rejected(): void
    {
        $orgA = Organization::factory()->create();
        $employeeA = Tenancy::runAs($orgA->id, fn () => User::factory()->create(['organization_id' => $orgA->id]));
        $proposalA = Tenancy::runAs($orgA->id, fn () => $this->proposal($employeeA));

        $orgB = Organization::factory()->create();
        $managerB = Tenancy::runAs($orgB->id, fn () => User::factory()->create(['organization_id' => $orgB->id]));

        $this->actingAs($employeeA);

        $this->expectException(RuntimeException::class);

        ProposalVersion::create([
            'proposal_id' => $proposalA->id,
            'version_number' => 1,
            'lifecycle_status' => ProposalVersionLifecycle::Draft,
            'manager_reviewed_by' => $managerB->id,
        ]);
    }

    public function test_a_second_draft_version_for_the_same_proposal_is_rejected_by_the_database(): void
    {
        $owner = User::factory()->create();
        $proposal = $this->proposal($owner);

        ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'version_number' => 1,
            'lifecycle_status' => ProposalVersionLifecycle::Draft,
        ]);

        $this->expectException(QueryException::class);

        ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'version_number' => 2,
            'lifecycle_status' => ProposalVersionLifecycle::Draft,
        ]);
    }

    public function test_a_non_draft_second_version_for_the_same_proposal_is_allowed(): void
    {
        // Positive control for the draft_lock_key generated column: any
        // number of non-Draft rows for the same Proposal must coexist
        // freely — only two simultaneous Draft rows collide.
        $owner = User::factory()->create();
        $proposal = $this->proposal($owner);

        ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'version_number' => 1,
            'lifecycle_status' => ProposalVersionLifecycle::Sent,
        ]);

        $second = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'version_number' => 2,
            'lifecycle_status' => ProposalVersionLifecycle::Draft,
        ]);

        $this->assertDatabaseCount('proposal_versions', 2);
        $this->assertTrue($second->exists);
    }

    public function test_duplicate_version_number_for_the_same_proposal_is_rejected_by_the_database(): void
    {
        $owner = User::factory()->create();
        $proposal = $this->proposal($owner);

        ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'version_number' => 1,
            'lifecycle_status' => ProposalVersionLifecycle::Sent,
        ]);

        $this->expectException(QueryException::class);

        ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'version_number' => 1,
            'lifecycle_status' => ProposalVersionLifecycle::Approved,
        ]);
    }

    public function test_marking_a_proposal_won_with_a_winning_version_id_succeeds(): void
    {
        $owner = User::factory()->create();
        $proposal = $this->proposal($owner, ProposalStage::CustomerAccepted);

        $version = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'version_number' => 1,
            'lifecycle_status' => ProposalVersionLifecycle::Sent,
        ]);

        $proposal->forceFill(['winning_version_id' => $version->id, 'current_version_id' => $version->id])->save();
        $proposal->outcome = ProposalOutcome::Won;
        $proposal->notes = 'Client confirmed acceptance.';
        $proposal->save();

        $this->assertSame(ProposalOutcome::Won, $proposal->fresh()->outcome);
        $this->assertSame($version->id, $proposal->fresh()->winningVersion->id);
    }
}
