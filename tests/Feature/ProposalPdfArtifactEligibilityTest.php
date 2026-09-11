<?php

namespace Tests\Feature;

use App\Enums\ProposalPdfArtifactStatus;
use App\Enums\ProposalVersionLifecycle;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Proposal;
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
 * Phase 4A-3.2, Section H: ProposalPdfArtifactService eligibility —
 * generate() is Approved-lifecycle-only; correct() is Approved-or-Sent;
 * both refuse a legacy backfilled Version outright (no fabricated
 * history).
 */
class ProposalPdfArtifactEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolates and auto-clears the private disk per test — never the
        // real storage/app/private, and never leaks state (including
        // reused auto-increment ids) across test runs.
        Storage::fake('local');

        config(['aculyze.organization_identity' => [
            'legal_name' => 'Aculyze Solutions Pvt Ltd',
            'registered_address' => '123 Business Park, Bengaluru',
            'gstin' => '29ABCDE1234F1Z5',
        ]]);
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
            'notes' => 'Fixture.',
        ]);

        return Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => 'being_prepared',
        ]);
    }

    /**
     * ProposalVersionLine::booted() refuses to create a line unless the
     * parent Version is currently Draft — so the line is always created
     * while the Version genuinely is Draft, and the target lifecycle is
     * forced afterward (that guard only fires on line create/update, never
     * on the Version's own lifecycle_status write).
     */
    private function versionWithLine(Proposal $proposal, ProposalVersionLifecycle $lifecycle, bool $legacy = false): ProposalVersion
    {
        $version = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'lifecycle_status' => ProposalVersionLifecycle::Draft,
            'is_legacy_backfill' => $legacy,
            'customer_name_snapshot' => 'Acme Corp',
        ]);

        if (! $legacy) {
            ProposalVersionLine::create([
                'proposal_version_id' => $version->id,
                'line_number' => 1,
                'item_name' => 'Widget',
                'quantity' => 1,
                'unit_price' => 100,
            ]);
        }

        if ($lifecycle !== ProposalVersionLifecycle::Draft) {
            $version->forceFill(['lifecycle_status' => $lifecycle])->save();
        }

        $proposal->forceFill(['current_version_id' => $version->id])->save();

        return $version->fresh();
    }

    public function test_approved_modern_version_can_generate(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposalFor($employee);
        $version = $this->versionWithLine($proposal, ProposalVersionLifecycle::Approved);

        $artifact = app(ProposalPdfArtifactService::class)->generateIfMissing($version, $employee);

        $this->assertSame(ProposalPdfArtifactStatus::Success, $artifact->status);
    }

    public function test_draft_cannot_generate_final_pdf(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposalFor($employee);
        $version = $this->versionWithLine($proposal, ProposalVersionLifecycle::Draft);

        $this->expectException(LogicException::class);
        app(ProposalPdfArtifactService::class)->generateIfMissing($version, $employee);
    }

    public function test_submitted_cannot_generate_final_pdf(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposalFor($employee);
        $version = $this->versionWithLine($proposal, ProposalVersionLifecycle::Submitted);

        $this->expectException(LogicException::class);
        app(ProposalPdfArtifactService::class)->generateIfMissing($version, $employee);
    }

    public function test_returned_for_revision_cannot_generate_final_pdf(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposalFor($employee);
        $version = $this->versionWithLine($proposal, ProposalVersionLifecycle::ReturnedForRevision);

        $this->expectException(LogicException::class);
        app(ProposalPdfArtifactService::class)->generateIfMissing($version, $employee);
    }

    public function test_legacy_backfilled_sent_cannot_generate_final_pdf(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposalFor($employee);
        $version = $this->versionWithLine($proposal, ProposalVersionLifecycle::Sent, legacy: true);

        $this->expectException(LogicException::class);
        app(ProposalPdfArtifactService::class)->generateIfMissing($version, $employee);
    }

    public function test_legacy_backfilled_sent_cannot_use_correction_path_either(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposalFor($employee);
        $version = $this->versionWithLine($proposal, ProposalVersionLifecycle::Sent, legacy: true);

        $this->expectException(LogicException::class);
        app(ProposalPdfArtifactService::class)->correct($version, $employee, 'Attempted correction reason.');
    }

    public function test_sent_modern_version_can_use_correction_path_when_a_primary_already_exists(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposalFor($employee);
        $version = $this->versionWithLine($proposal, ProposalVersionLifecycle::Approved);

        app(ProposalPdfArtifactService::class)->generateIfMissing($version, $employee);

        $version->forceFill(['lifecycle_status' => ProposalVersionLifecycle::Sent])->save();

        $corrected = app(ProposalPdfArtifactService::class)->correct($version->fresh(), $employee, 'Letterhead update.');

        $this->assertSame(ProposalPdfArtifactStatus::Success, $corrected->status);
        $this->assertSame('Letterhead update.', $corrected->correction_reason);
    }

    public function test_sent_modern_version_cannot_use_generate_normally(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposalFor($employee);
        $version = $this->versionWithLine($proposal, ProposalVersionLifecycle::Sent);

        $this->expectException(LogicException::class);
        app(ProposalPdfArtifactService::class)->generateIfMissing($version, $employee);
    }
}
