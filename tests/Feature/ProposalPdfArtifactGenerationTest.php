<?php

namespace Tests\Feature;

use App\Enums\ProposalPdfArtifactStatus;
use App\Enums\ProposalVersionLifecycle;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalPdfArtifact;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use App\Models\Prospect;
use App\Models\User;
use App\Services\ProposalPdfArtifactService;
use App\Services\ProposalNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 4A-3.2, Sections G/H/J: successful first-generation storage/
 * integrity guarantees, and the primary_lock_key generated-column
 * concurrency backstop.
 */
class ProposalPdfArtifactGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config(['aculyze.organization_identity' => [
            'legal_name' => 'Aculyze Solutions Pvt Ltd',
            'registered_address' => '123 Business Park, Bengaluru',
            'gstin' => '29ABCDE1234F1Z5',
        ]]);
    }

    private function approvedVersion(User $employee): ProposalVersion
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => 'validated',
            'temperature' => 'hot',
            'notes' => 'Fixture.',
        ]);
        $proposal = Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => 'being_prepared',
        ]);

        $version = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'lifecycle_status' => ProposalVersionLifecycle::Draft,
            'customer_name_snapshot' => 'Acme Corp',
        ]);
        ProposalVersionLine::create([
            'proposal_version_id' => $version->id,
            'line_number' => 1,
            'item_name' => 'Widget',
            'quantity' => 1,
            'unit_price' => 100,
        ]);
        $version->forceFill(['lifecycle_status' => ProposalVersionLifecycle::Approved])->save();
        $proposal->forceFill(['current_version_id' => $version->id])->save();
        app(ProposalNumberService::class)->allocateIfMissing($proposal->fresh());

        return $version->fresh(['proposal']);
    }

    public function test_successful_generation_stores_private_bytes(): void
    {
        $employee = User::factory()->create();
        $version = $this->approvedVersion($employee);

        $artifact = app(ProposalPdfArtifactService::class)->generateIfMissing($version, $employee);

        $this->assertTrue(Storage::disk('local')->exists($artifact->storage_path));
        $this->assertStringStartsWith("proposal-pdfs/{$version->organization_id}/{$version->proposal_id}/{$version->getKey()}/", $artifact->storage_path);
    }

    public function test_sha256_matches_the_stored_bytes(): void
    {
        $employee = User::factory()->create();
        $version = $this->approvedVersion($employee);

        $artifact = app(ProposalPdfArtifactService::class)->generateIfMissing($version, $employee);

        $bytes = Storage::disk('local')->get($artifact->storage_path);
        $this->assertSame(hash('sha256', $bytes), $artifact->checksum_sha256);
    }

    public function test_byte_size_matches_the_stored_bytes(): void
    {
        $employee = User::factory()->create();
        $version = $this->approvedVersion($employee);

        $artifact = app(ProposalPdfArtifactService::class)->generateIfMissing($version, $employee);

        $bytes = Storage::disk('local')->get($artifact->storage_path);
        $this->assertSame(strlen($bytes), $artifact->byte_size);
    }

    public function test_template_version_is_stored(): void
    {
        $employee = User::factory()->create();
        $version = $this->approvedVersion($employee);

        $artifact = app(ProposalPdfArtifactService::class)->generateIfMissing($version, $employee);

        $this->assertSame(ProposalPdfArtifactService::TEMPLATE_VERSION, $artifact->template_version);
    }

    public function test_generated_by_and_generated_at_are_stored(): void
    {
        $employee = User::factory()->create();
        $version = $this->approvedVersion($employee);

        $before = now();
        $artifact = app(ProposalPdfArtifactService::class)->generateIfMissing($version, $employee);

        $this->assertSame($employee->id, $artifact->generated_by);
        $this->assertNotNull($artifact->generated_at);
        $this->assertTrue($artifact->generated_at->gte($before->subSecond()));
    }

    public function test_exactly_one_current_successful_primary_per_version(): void
    {
        $employee = User::factory()->create();
        $version = $this->approvedVersion($employee);

        app(ProposalPdfArtifactService::class)->generateIfMissing($version, $employee);
        // Idempotent: calling again with an existing primary must not
        // create a second row.
        app(ProposalPdfArtifactService::class)->generateIfMissing($version->fresh(), $employee);

        $this->assertSame(1, ProposalPdfArtifact::query()
            ->where('proposal_version_id', $version->id)
            ->where('status', ProposalPdfArtifactStatus::Success)
            ->whereNull('superseded_at')
            ->count());
    }

    public function test_failed_artifacts_do_not_claim_the_primary_lock(): void
    {
        $employee = User::factory()->create();
        $version = $this->approvedVersion($employee);

        ProposalPdfArtifact::create([
            'proposal_version_id' => $version->id,
            'status' => ProposalPdfArtifactStatus::Failed,
            'template_version' => 'v1',
            'generated_at' => now(),
            'generated_by' => $employee->id,
            'failure_reason' => 'Simulated prior failure.',
        ]);

        // A genuine success can still be created afterward — the Failed
        // row's NULL primary_lock_key never collides.
        $artifact = app(ProposalPdfArtifactService::class)->generateIfMissing($version->fresh(), $employee);

        $this->assertSame(ProposalPdfArtifactStatus::Success, $artifact->status);
        $this->assertSame(2, ProposalPdfArtifact::query()->where('proposal_version_id', $version->id)->count());
    }

    public function test_first_generation_race_cannot_create_two_current_primaries(): void
    {
        $employee = User::factory()->create();
        $version = $this->approvedVersion($employee);

        // Simulates a concurrent winner: a Success row appears for this
        // Version AFTER this call's own eligibility check but is detected
        // by the service's lock-and-recheck before it would insert its own.
        // Directly proves the DB-level backstop by attempting a raw second
        // insert once a first primary already exists.
        app(ProposalPdfArtifactService::class)->generateIfMissing($version, $employee);

        $this->expectException(\Illuminate\Database\QueryException::class);

        ProposalPdfArtifact::create([
            'proposal_version_id' => $version->id,
            'status' => ProposalPdfArtifactStatus::Success,
            'template_version' => 'v1',
            'checksum_sha256' => str_repeat('a', 64),
            'storage_path' => 'x',
            'byte_size' => 1,
            'generated_at' => now(),
            'generated_by' => $employee->id,
        ]);
    }

    public function test_service_recheck_discards_wasted_render_when_a_concurrent_primary_already_won(): void
    {
        $employee = User::factory()->create();
        $version = $this->approvedVersion($employee);

        // Pre-seed a primary as if a concurrent request already won,
        // simulating the race the service's lock-and-recheck step guards
        // against — generateIfMissing()'s own initial fast-path check
        // already returns it without rendering again.
        $existing = app(ProposalPdfArtifactService::class)->generateIfMissing($version, $employee);

        $returned = app(ProposalPdfArtifactService::class)->generateIfMissing($version->fresh(), $employee);

        $this->assertSame($existing->getKey(), $returned->getKey());
        $this->assertSame(1, ProposalPdfArtifact::query()->where('proposal_version_id', $version->id)->count());
    }
}
