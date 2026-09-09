<?php

namespace Tests\Feature;

use App\Enums\ProposalPdfArtifactStatus;
use App\Enums\ProposalVersionLifecycle;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalPdfArtifact;
use App\Models\ProposalVersion;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4A-3.1: proposal_pdf_artifacts.primary_lock_key — the STORED
 * generated column enforcing "at most one current primary successful
 * artifact per Version" without a native partial/filtered unique index,
 * the same technique already proven by proposal_versions.draft_lock_key.
 * No service exists yet (4A-3.2) — this exercises the constraint directly.
 */
class ProposalPdfArtifactPrimaryLockTest extends TestCase
{
    use RefreshDatabase;

    private function versionFor(User $actor): ProposalVersion
    {
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
    }

    private function artifact(ProposalVersion $version, User $actor, array $overrides = []): ProposalPdfArtifact
    {
        return ProposalPdfArtifact::create(array_merge([
            'proposal_version_id' => $version->id,
            'status' => ProposalPdfArtifactStatus::Success,
            'template_version' => 'v1',
            'checksum_sha256' => str_repeat('a', 64),
            'storage_path' => 'proposal-pdf-artifacts/1/1.pdf',
            'byte_size' => 1024,
            'generated_at' => now(),
            'generated_by' => $actor->id,
        ], $overrides));
    }

    public function test_multiple_failed_rows_are_allowed(): void
    {
        $actor = User::factory()->create();
        $version = $this->versionFor($actor);

        $this->artifact($version, $actor, ['status' => ProposalPdfArtifactStatus::Failed, 'checksum_sha256' => null, 'storage_path' => null, 'byte_size' => null, 'failure_reason' => 'Render error 1']);
        $this->artifact($version, $actor, ['status' => ProposalPdfArtifactStatus::Failed, 'checksum_sha256' => null, 'storage_path' => null, 'byte_size' => null, 'failure_reason' => 'Render error 2']);

        $this->assertSame(2, ProposalPdfArtifact::query()->where('proposal_version_id', $version->id)->count());
    }

    public function test_multiple_superseded_success_rows_are_allowed(): void
    {
        $actor = User::factory()->create();
        $version = $this->versionFor($actor);

        $first = $this->artifact($version, $actor);
        $first->forceFill(['superseded_at' => now()])->save();

        $second = $this->artifact($version, $actor, ['checksum_sha256' => str_repeat('b', 64)]);
        $second->forceFill(['superseded_at' => now(), 'superseded_by_artifact_id' => null])->save();

        // A third, still-current row may now be created without colliding
        // with either superseded row above.
        $third = $this->artifact($version, $actor, ['checksum_sha256' => str_repeat('c', 64)]);

        $this->assertSame(3, ProposalPdfArtifact::query()->where('proposal_version_id', $version->id)->count());
        $this->assertNotNull($third->fresh());
    }

    public function test_only_one_current_successful_primary_per_version(): void
    {
        $actor = User::factory()->create();
        $version = $this->versionFor($actor);

        $this->artifact($version, $actor);

        $this->expectException(QueryException::class);
        $this->artifact($version, $actor, ['checksum_sha256' => str_repeat('d', 64)]);
    }

    public function test_correction_frees_the_lock_for_a_new_primary(): void
    {
        $actor = User::factory()->create();
        $version = $this->versionFor($actor);

        $original = $this->artifact($version, $actor);

        // Update-then-insert order matters under primary_lock_key: the old
        // primary must be superseded BEFORE the new one is inserted, or the
        // new insert collides with the still-claimed lock (see
        // ProposalPdfArtifact's own migration docblock).
        $original->forceFill(['superseded_at' => now()])->save();

        $corrected = $this->artifact($version, $actor, ['checksum_sha256' => str_repeat('e', 64), 'correction_reason' => 'Letterhead fix']);
        $original->forceFill(['superseded_by_artifact_id' => $corrected->id])->save();

        $this->assertNotNull($original->fresh()->superseded_at);
        $this->assertSame($corrected->id, $original->fresh()->superseded_by_artifact_id);
    }

    public function test_a_different_version_may_have_its_own_current_primary(): void
    {
        $actor = User::factory()->create();
        $versionOne = $this->versionFor($actor);
        $versionTwo = $this->versionFor($actor);

        $this->artifact($versionOne, $actor);
        $this->artifact($versionTwo, $actor, ['checksum_sha256' => str_repeat('f', 64)]);

        $this->assertSame(1, ProposalPdfArtifact::query()->where('proposal_version_id', $versionOne->id)->count());
        $this->assertSame(1, ProposalPdfArtifact::query()->where('proposal_version_id', $versionTwo->id)->count());
    }
}
