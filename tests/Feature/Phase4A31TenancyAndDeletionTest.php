<?php

namespace Tests\Feature;

use App\Enums\ProposalPdfArtifactStatus;
use App\Enums\ProposalVersionLifecycle;
use App\Enums\UserRole;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Proposal;
use App\Models\ProposalPdfArtifact;
use App\Models\ProposalVersion;
use App\Models\Prospect;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 4A-3.1: tenant isolation (OrganizationScope) and FK/deletion
 * behavior (RESTRICT) for the six new models — no delete UI/action exists
 * for any of them, so these exercise the constraints directly, the same
 * way ProposalPdfArtifactPrimaryLockTest etc. exercise the generated-column
 * locks directly.
 */
class Phase4A31TenancyAndDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgWithUser(UserRole $role = UserRole::Employee): array
    {
        $organization = Organization::factory()->create();
        $user = Tenancy::runAs(
            $organization->id,
            fn () => User::factory()->create(['organization_id' => $organization->id, 'role' => $role])
        );

        return [$organization, $user];
    }

    private function versionFor(Organization $organization, User $actor): ProposalVersion
    {
        return Tenancy::runAs($organization->id, function () use ($actor) {
            $prospect = Prospect::factory()->create(['assigned_to' => $actor->id, 'created_by' => $actor->id]);
            $lead = Lead::create([
                'prospect_id' => $prospect->id,
                'assigned_to' => $actor->id,
                'created_by' => $actor->id,
                'stage' => 'validated',
                'temperature' => 'hot',
                'notes' => 'Fixture.',
            ]);
            $proposal = Proposal::create([
                'lead_id' => $lead->id,
                'prospect_id' => $prospect->id,
                'assigned_to' => $actor->id,
                'created_by' => $actor->id,
                'stage' => 'being_prepared',
            ]);

            $version = ProposalVersion::factory()->create([
                'proposal_id' => $proposal->id,
                'lifecycle_status' => ProposalVersionLifecycle::Approved,
            ]);

            $proposal->forceFill(['current_version_id' => $version->id])->save();

            return $version->fresh();
        });
    }

    private function artifact(ProposalVersion $version, User $actor): ProposalPdfArtifact
    {
        return ProposalPdfArtifact::create([
            'proposal_version_id' => $version->id,
            'status' => ProposalPdfArtifactStatus::Success,
            'template_version' => 'v1',
            'checksum_sha256' => str_repeat('a', 64),
            'storage_path' => 'proposal-pdf-artifacts/1/1.pdf',
            'byte_size' => 1024,
            'generated_at' => now(),
            'generated_by' => $actor->id,
        ]);
    }

    public function test_org_a_cannot_see_org_bs_pdf_artifact(): void
    {
        [$orgB, $userB] = $this->makeOrgWithUser();
        $versionB = $this->versionFor($orgB, $userB);
        $artifactB = Tenancy::runAs($orgB->id, fn () => $this->artifact($versionB, $userB));

        [$orgA, $userA] = $this->makeOrgWithUser();

        $visible = Tenancy::runAs($orgA->id, fn () => ProposalPdfArtifact::query()->whereKey($artifactB->id)->exists());

        $this->assertFalse($visible);
    }

    public function test_org_a_can_see_its_own_pdf_artifact(): void
    {
        [$orgA, $userA] = $this->makeOrgWithUser();
        $versionA = $this->versionFor($orgA, $userA);
        $artifactA = Tenancy::runAs($orgA->id, fn () => $this->artifact($versionA, $userA));

        $visible = Tenancy::runAs($orgA->id, fn () => ProposalPdfArtifact::query()->whereKey($artifactA->id)->exists());

        $this->assertTrue($visible);
    }

    public function test_creating_a_pdf_artifact_referencing_another_organizations_user_is_rejected(): void
    {
        [$orgA, $userA] = $this->makeOrgWithUser();
        [$orgB, $userB] = $this->makeOrgWithUser();
        $versionA = $this->versionFor($orgA, $userA);

        // organization_id is inherited from proposal_version_id (Org A), so
        // generated_by pointing at an Org B user is what
        // EnforcesSameOrganizationRelations must catch — mirrors
        // CrossOrganizationRelationshipInjectionTest's own CallRecord case.
        $this->expectException(RuntimeException::class);

        Tenancy::runAs($orgA->id, fn () => ProposalPdfArtifact::create([
            'proposal_version_id' => $versionA->id,
            'status' => ProposalPdfArtifactStatus::Success,
            'template_version' => 'v1',
            'checksum_sha256' => str_repeat('a', 64),
            'storage_path' => 'x',
            'byte_size' => 1,
            'generated_at' => now(),
            'generated_by' => $userB->id,
        ]));
    }

    public function test_a_proposal_version_referenced_by_a_pdf_artifact_cannot_be_deleted(): void
    {
        [$org, $user] = $this->makeOrgWithUser();
        $version = $this->versionFor($org, $user);
        Tenancy::runAs($org->id, fn () => $this->artifact($version, $user));

        $this->expectException(QueryException::class);
        Tenancy::runAs($org->id, fn () => $version->fresh()->delete());
    }

    public function test_a_pdf_artifact_referenced_by_a_send_cannot_be_deleted(): void
    {
        [$org, $user] = $this->makeOrgWithUser();
        $version = $this->versionFor($org, $user);
        $artifact = Tenancy::runAs($org->id, fn () => $this->artifact($version, $user));

        Tenancy::runAs($org->id, fn () => \App\Models\ProposalSend::create([
            'proposal_id' => $version->proposal_id,
            'proposal_version_id' => $version->id,
            'pdf_artifact_id' => $artifact->id,
            'method' => \App\Enums\ProposalSendMethod::Manual,
            'status' => \App\Enums\ProposalSendStatus::Sent,
            'to_recipients' => ['client@example.com'],
            'attempted_at' => now(),
            'attempted_by' => $user->id,
            'sent_at' => now(),
            'sent_by' => $user->id,
            'idempotency_key' => (string) str()->uuid(),
        ]));

        $this->expectException(QueryException::class);
        Tenancy::runAs($org->id, fn () => $artifact->fresh()->delete());
    }

    public function test_a_user_who_generated_a_pdf_artifact_cannot_be_raw_deleted(): void
    {
        [$org, $user] = $this->makeOrgWithUser();
        $version = $this->versionFor($org, $user);
        Tenancy::runAs($org->id, fn () => $this->artifact($version, $user));

        $this->expectException(QueryException::class);
        // Deliberately bypassing EmployeeDeletionService — proves the raw
        // RESTRICT constraint itself holds, independent of that service's
        // own (not-yet-extended, see Phase 4A-3.1 report) blocker checks.
        $user->delete();
    }
}
