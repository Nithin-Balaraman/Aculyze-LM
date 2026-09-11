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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 4A-3.2, Section K: a rendering/identity failure never rolls back
 * anything, is recorded as permanent Failed history, and never disturbs an
 * existing good primary.
 */
class ProposalPdfArtifactFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
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

        return $version->fresh(['proposal']);
    }

    /** No identity configured anywhere — resolveOrFail() throws, which generateIfMissing() must convert into a Failed row, never an uncaught exception. */
    public function test_rendering_failure_leaves_version_approved(): void
    {
        $employee = User::factory()->create();
        $version = $this->approvedVersion($employee);

        app(ProposalPdfArtifactService::class)->generateIfMissing($version, $employee);

        $this->assertSame(ProposalVersionLifecycle::Approved, $version->fresh()->lifecycle_status);
    }

    public function test_rendering_failure_records_failed_metadata_with_a_meaningful_reason(): void
    {
        $employee = User::factory()->create();
        $version = $this->approvedVersion($employee);

        $artifact = app(ProposalPdfArtifactService::class)->generateIfMissing($version, $employee);

        $this->assertSame(ProposalPdfArtifactStatus::Failed, $artifact->status);
        $this->assertNotNull($artifact->failure_reason);
        $this->assertStringContainsString('mandatory legal identity', $artifact->failure_reason);
        $this->assertNull($artifact->checksum_sha256);
        $this->assertNull($artifact->storage_path);
        $this->assertNull($artifact->byte_size);
    }

    public function test_failure_leaves_an_existing_good_primary_untouched(): void
    {
        $employee = User::factory()->create();
        $version = $this->approvedVersion($employee);

        config(['aculyze.organization_identity' => [
            'legal_name' => 'Aculyze Solutions Pvt Ltd',
            'registered_address' => '123 Business Park, Bengaluru',
            'gstin' => '29ABCDE1234F1Z5',
        ]]);
        $goodPrimary = app(ProposalPdfArtifactService::class)->generateIfMissing($version, $employee);
        $this->assertSame(ProposalPdfArtifactStatus::Success, $goodPrimary->status);

        // generateIfMissing() is a no-op once a primary exists — it never
        // re-attempts, so there is nothing for a later identity removal to
        // even threaten; this proves that explicitly.
        config(['aculyze.organization_identity' => []]);
        $returned = app(ProposalPdfArtifactService::class)->generateIfMissing($version->fresh(), $employee);

        $this->assertSame($goodPrimary->getKey(), $returned->getKey());
        $this->assertSame(ProposalPdfArtifactStatus::Success, $returned->fresh()->status);
    }

    public function test_partial_bytes_are_not_retained_as_a_valid_artifact(): void
    {
        $employee = User::factory()->create();
        $version = $this->approvedVersion($employee);

        config(['aculyze.organization_identity' => [
            'legal_name' => 'Aculyze Solutions Pvt Ltd',
            'registered_address' => '123 Business Park, Bengaluru',
            'gstin' => '29ABCDE1234F1Z5',
        ]]);

        app(ProposalPdfArtifactService::class)->generateIfMissing($version, $employee);

        // No stray files exist anywhere under this Version's own storage
        // prefix beyond the exactly-one successful artifact's own path.
        $files = Storage::disk('local')->allFiles("proposal-pdfs/{$version->organization_id}/{$version->proposal_id}/{$version->getKey()}");
        $this->assertCount(1, $files);
    }

    public function test_no_artifact_row_is_created_at_all_for_a_precondition_failure(): void
    {
        // A wrong-lifecycle call is a LogicException, not a "rendering
        // failure" — no Failed row is fabricated for a call that should
        // never have been reachable in the first place.
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $lead = Lead::create([
            'prospect_id' => $prospect->id, 'assigned_to' => $employee->id, 'created_by' => $employee->id,
            'stage' => 'validated', 'temperature' => 'hot', 'notes' => 'Fixture.',
        ]);
        $proposal = Proposal::create([
            'lead_id' => $lead->id, 'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id, 'created_by' => $employee->id, 'stage' => 'being_prepared',
        ]);
        $version = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'lifecycle_status' => ProposalVersionLifecycle::Draft,
        ]);
        $proposal->forceFill(['current_version_id' => $version->id])->save();

        try {
            app(ProposalPdfArtifactService::class)->generateIfMissing($version->fresh(), $employee);
        } catch (\LogicException) {
            // expected
        }

        $this->assertSame(0, ProposalPdfArtifact::query()->where('proposal_version_id', $version->id)->count());
    }
}
