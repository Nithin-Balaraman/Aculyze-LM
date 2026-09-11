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
use LogicException;
use Tests\TestCase;

/**
 * Phase 4A-3.2, Sections I/N: rendering-only PDF correction — safe two-
 * phase ordering (render+store fully succeeds BEFORE any DB swap), mandatory
 * reason, permanent old-artifact preservation, no lifecycle/commercial-field
 * change.
 */
class ProposalPdfArtifactCorrectionTest extends TestCase
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

    private function approvedVersionWithPrimary(User $employee): ProposalVersion
    {
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
            'customer_name_snapshot' => 'Acme Corp',
        ]);
        ProposalVersionLine::create([
            'proposal_version_id' => $version->id, 'line_number' => 1,
            'item_name' => 'Widget', 'quantity' => 1, 'unit_price' => 100,
        ]);
        $version->forceFill(['lifecycle_status' => ProposalVersionLifecycle::Approved])->save();
        $proposal->forceFill(['current_version_id' => $version->id])->save();

        app(ProposalPdfArtifactService::class)->generateIfMissing($version->fresh(), $employee);

        return $version->fresh(['proposal']);
    }

    public function test_correction_reason_is_mandatory(): void
    {
        $employee = User::factory()->create();
        $version = $this->approvedVersionWithPrimary($employee);

        $this->expectException(LogicException::class);
        app(ProposalPdfArtifactService::class)->correct($version, $employee, '');
    }

    public function test_successful_correction_preserves_the_old_artifact_permanently(): void
    {
        $employee = User::factory()->create();
        $version = $this->approvedVersionWithPrimary($employee);
        $original = app(ProposalPdfArtifactService::class)->currentPrimary($version);

        app(ProposalPdfArtifactService::class)->correct($version->fresh(), $employee, 'Letterhead fix.');

        $originalFresh = $original->fresh();
        $this->assertTrue(Storage::disk('local')->exists($originalFresh->storage_path));
        $this->assertSame(ProposalPdfArtifactStatus::Success, $originalFresh->status);
        $this->assertNotNull($originalFresh->superseded_at);
    }

    public function test_old_artifact_is_superseded_only_after_replacement_render_succeeds(): void
    {
        $employee = User::factory()->create();
        $version = $this->approvedVersionWithPrimary($employee);
        $original = app(ProposalPdfArtifactService::class)->currentPrimary($version);

        $corrected = app(ProposalPdfArtifactService::class)->correct($version->fresh(), $employee, 'Letterhead fix.');

        $this->assertSame($corrected->getKey(), $original->fresh()->superseded_by_artifact_id);
        $this->assertNull($corrected->superseded_at);
    }

    public function test_new_artifact_becomes_the_current_primary(): void
    {
        $employee = User::factory()->create();
        $version = $this->approvedVersionWithPrimary($employee);

        $corrected = app(ProposalPdfArtifactService::class)->correct($version->fresh(), $employee, 'Letterhead fix.');

        $current = app(ProposalPdfArtifactService::class)->currentPrimary($version->fresh());
        $this->assertSame($corrected->getKey(), $current->getKey());
    }

    public function test_correction_on_sent_version_succeeds(): void
    {
        $employee = User::factory()->create();
        $version = $this->approvedVersionWithPrimary($employee);
        $version->forceFill(['lifecycle_status' => ProposalVersionLifecycle::Sent])->save();

        $corrected = app(ProposalPdfArtifactService::class)->correct($version->fresh(), $employee, 'Post-send correction.');

        $this->assertSame(ProposalPdfArtifactStatus::Success, $corrected->status);
    }

    public function test_correction_does_not_alter_frozen_fields_or_lifecycle(): void
    {
        $employee = User::factory()->create();
        $version = $this->approvedVersionWithPrimary($employee);
        $originalLifecycle = $version->lifecycle_status;
        $originalCustomerName = $version->customer_name_snapshot;

        app(ProposalPdfArtifactService::class)->correct($version->fresh(), $employee, 'Letterhead fix.');

        $fresh = $version->fresh();
        $this->assertSame($originalLifecycle, $fresh->lifecycle_status);
        $this->assertSame($originalCustomerName, $fresh->customer_name_snapshot);
    }

    public function test_failed_correction_leaves_the_old_primary_current(): void
    {
        $employee = User::factory()->create();
        $version = $this->approvedVersionWithPrimary($employee);
        $original = app(ProposalPdfArtifactService::class)->currentPrimary($version);

        // Remove identity so the correction's own render fails.
        config(['aculyze.organization_identity' => []]);

        $result = app(ProposalPdfArtifactService::class)->correct($version->fresh(), $employee, 'Attempted but will fail.');

        $this->assertSame(ProposalPdfArtifactStatus::Failed, $result->status);
        $originalFresh = $original->fresh();
        $this->assertNull($originalFresh->superseded_at);
        $this->assertSame($original->getKey(), app(ProposalPdfArtifactService::class)->currentPrimary($version->fresh())?->getKey());
    }

    public function test_correction_requires_an_existing_primary_to_replace(): void
    {
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
            'proposal_id' => $proposal->id, 'lifecycle_status' => ProposalVersionLifecycle::Draft,
            'customer_name_snapshot' => 'Acme Corp',
        ]);
        ProposalVersionLine::create([
            'proposal_version_id' => $version->id, 'line_number' => 1,
            'item_name' => 'Widget', 'quantity' => 1, 'unit_price' => 100,
        ]);
        $version->forceFill(['lifecycle_status' => ProposalVersionLifecycle::Approved])->save();
        $proposal->forceFill(['current_version_id' => $version->id])->save();

        $this->expectException(LogicException::class);
        app(ProposalPdfArtifactService::class)->correct($version->fresh(), $employee, 'No primary exists yet.');
    }

    /**
     * The genuine mid-render race (a concurrent correction completes
     * between this call's own precondition read and its Phase 2 DB swap)
     * requires true concurrent execution to trigger — not reproducible in
     * a single-process, single-request test without adding a production
     * code seam purely for testability, which was deliberately not done.
     * This instead verifies the safe SEQUENTIAL case robustly: each of two
     * back-to-back corrections targets whatever is genuinely current at
     * the moment it actually runs, forming a correct A→B→C supersession
     * chain — never re-targeting a primary that has already moved on. The
     * mismatch-guard code path itself (correct()'s re-read-inside-the-lock
     * comparison) is defense-in-depth verified by inspection, the same way
     * the generated-column UNIQUE backstop is a verified-by-constraint
     * safety net beneath the application-level lock-and-recheck logic
     * proposal_pdf_artifacts.primary_lock_key already tests directly.
     */
    public function test_sequential_corrections_form_a_correct_supersession_chain(): void
    {
        $employee = User::factory()->create();
        $version = $this->approvedVersionWithPrimary($employee);
        $artifactA = app(ProposalPdfArtifactService::class)->currentPrimary($version);

        $artifactB = app(ProposalPdfArtifactService::class)->correct($version->fresh(), $employee, 'First correction.');
        $artifactC = app(ProposalPdfArtifactService::class)->correct($version->fresh(), $employee, 'Second correction.');

        $this->assertSame($artifactB->getKey(), $artifactA->fresh()->superseded_by_artifact_id);
        $this->assertSame($artifactC->getKey(), $artifactB->fresh()->superseded_by_artifact_id);
        $this->assertNull($artifactC->fresh()->superseded_at);
        $this->assertSame($artifactC->getKey(), app(ProposalPdfArtifactService::class)->currentPrimary($version->fresh())?->getKey());
        $this->assertSame(3, ProposalPdfArtifact::query()->where('proposal_version_id', $version->id)->count());
    }
}
