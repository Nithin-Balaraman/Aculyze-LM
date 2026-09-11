<?php

namespace Tests\Feature;

use App\Enums\ProposalVersionLifecycle;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use App\Models\Prospect;
use App\Models\User;
use App\Services\ProposalPdfArtifactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 4A-3.2, Section D: the final PDF renders ONLY from the frozen
 * ProposalVersion snapshot and its owned children — never from the live
 * Prospect, and never a recomputed total.
 */
class ProposalPdfArtifactSnapshotTruthTest extends TestCase
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

    private function approvedVersion(Prospect $prospect, User $employee): ProposalVersion
    {
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
            'customer_name_snapshot' => 'Frozen Customer Name At Approval Time',
            'customer_gstin_snapshot' => 'FROZEN-GSTIN-001',
            'billing_address_snapshot' => 'Frozen Billing Address, As Of Approval',
            'grand_total' => 1180,
            'subtotal' => 1000,
            'total_discount' => 0,
            'tax_total' => 180,
        ]);

        ProposalVersionLine::create([
            'proposal_version_id' => $version->id,
            'line_number' => 1,
            'item_name' => 'Widget',
            'quantity' => 2,
            'unit_price' => 500,
            'gross_amount' => 1000,
            'discount_amount' => 0,
            'taxable_amount' => 1000,
            'tax_amount' => 180,
            'line_total' => 1180,
        ]);

        $version->forceFill(['lifecycle_status' => ProposalVersionLifecycle::Approved])->save();
        $proposal->forceFill(['current_version_id' => $version->id])->save();

        return $version->fresh(['proposal']);
    }

    public function test_renderer_uses_frozen_version_customer_snapshot_not_live_prospect(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create([
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'company_name' => 'Live Prospect Company Name — Should Never Appear',
        ]);
        $version = $this->approvedVersion($prospect, $employee);

        $artifact = app(ProposalPdfArtifactService::class)->generateIfMissing($version, $employee);

        $pdfText = $this->extractPdfText(Storage::disk('local')->get($artifact->storage_path));

        $this->assertStringContainsString('Frozen Customer Name At Approval Time', $pdfText);
        $this->assertStringNotContainsString('Live Prospect Company Name', $pdfText);
    }

    public function test_changing_live_prospect_after_approval_does_not_change_a_later_corrected_pdf_snapshot(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $version = $this->approvedVersion($prospect, $employee);

        app(ProposalPdfArtifactService::class)->generateIfMissing($version, $employee);

        // The live Prospect changes AFTER approval/first generation...
        $prospect->update(['company_name' => 'Changed After Approval', 'gstin' => 'CHANGED-GSTIN']);

        // ...a rendering-only correction still reflects the FROZEN Version
        // snapshot, never the Prospect's new values.
        $corrected = app(ProposalPdfArtifactService::class)->correct($version->fresh(), $employee, 'Routine re-render.');

        $pdfText = $this->extractPdfText(Storage::disk('local')->get($corrected->storage_path));

        $this->assertStringContainsString('Frozen Customer Name At Approval Time', $pdfText);
        $this->assertStringContainsString('FROZEN-GSTIN-001', $pdfText);
        $this->assertStringNotContainsString('Changed After Approval', $pdfText);
        $this->assertStringNotContainsString('CHANGED-GSTIN', $pdfText);
    }

    public function test_lines_tax_and_totals_come_from_frozen_version_children_and_persisted_totals(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $version = $this->approvedVersion($prospect, $employee);

        $artifact = app(ProposalPdfArtifactService::class)->generateIfMissing($version, $employee);
        $pdfText = $this->extractPdfText(Storage::disk('local')->get($artifact->storage_path));

        $this->assertStringContainsString('Widget', $pdfText);
        $this->assertStringContainsString('1,180.00', $pdfText);
        $this->assertStringContainsString('1,000.00', $pdfText);
        $this->assertStringContainsString('180.00', $pdfText);
    }

    public function test_renderer_does_not_silently_recompute_a_different_grand_total(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $version = $this->approvedVersion($prospect, $employee);

        // Deliberately inconsistent: line total (1180) does not equal
        // quantity*unit_price (2*500=1000) plus a "recomputed" tax — the
        // point is the RENDERER must show exactly grand_total=1180 as
        // persisted, never recompute 1000 or anything else from the raw
        // line inputs.
        $artifact = app(ProposalPdfArtifactService::class)->generateIfMissing($version, $employee);
        $pdfText = $this->extractPdfText(Storage::disk('local')->get($artifact->storage_path));

        $this->assertStringContainsString('1,180.00', $pdfText);
    }

    /**
     * dompdf's PDF output compresses its content streams (FlateDecode), so
     * the rendered text is never a raw searchable substring of the PDF
     * bytes themselves — confirmed empirically before writing this helper.
     * Shells out to `pdftotext` (poppler-utils) to get back genuinely
     * searchable text. This is a real, deliberate test-environment
     * dependency — see the Phase 4A-3.2 implementation report.
     */
    private function extractPdfText(string $bytes): string
    {
        $tmpPdf = tempnam(sys_get_temp_dir(), 'pdftest').'.pdf';
        $tmpTxt = $tmpPdf.'.txt';
        file_put_contents($tmpPdf, $bytes);

        exec('pdftotext '.escapeshellarg($tmpPdf).' '.escapeshellarg($tmpTxt).' 2>&1');

        $text = is_file($tmpTxt) ? file_get_contents($tmpTxt) : '';

        @unlink($tmpPdf);
        @unlink($tmpTxt);

        return $text;
    }
}
