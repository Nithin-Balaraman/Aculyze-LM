<?php

namespace Tests\Feature;

use App\Enums\ProposalPdfArtifactStatus;
use App\Enums\ProposalVersionLifecycle;
use App\Enums\UserRole;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalPdfArtifact;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use App\Models\Prospect;
use App\Models\User;
use App\Services\ProposalPdfArtifactService;
use App\Services\ProposalVersionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 4A-3.2, Section L (locked Decision 3): approval commits first, PDF
 * generation auto-attempts immediately after via DB::afterCommit() — never
 * the reverse, and a rendering failure never rolls back or blocks the
 * approval itself.
 */
class ProposalPdfArtifactApprovalIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /** @return array{employee: User, manager: User, seniorManager: User} */
    private function hierarchy(): array
    {
        $seniorManager = User::factory()->admin()->create();
        $manager = User::factory()->create(['role' => UserRole::Manager, 'manager_id' => $seniorManager->id]);
        $employee = User::factory()->create(['role' => UserRole::Employee, 'manager_id' => $manager->id]);

        return compact('employee', 'manager', 'seniorManager');
    }

    private function submittedVersion(User $employee, User $submitter): ProposalVersion
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
            'item_name' => 'Widget', 'quantity' => 2, 'unit_price' => 500,
        ]);
        $proposal->forceFill(['current_version_id' => $version->id])->save();

        app(ProposalVersionWorkflowService::class)->submit($version->fresh(), $submitter);

        return $version->fresh();
    }

    public function test_approval_commits_even_if_auto_generation_fails(): void
    {
        // Deliberately no identity configured anywhere — auto-generation
        // will fail, but that must never touch the approval itself.
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->submittedVersion($employee, $manager);

        app(ProposalVersionWorkflowService::class)->approve($version, $seniorManager);

        $this->assertSame(ProposalVersionLifecycle::Approved, $version->fresh()->lifecycle_status);
        $this->assertNotNull($version->fresh()->approved_at);
    }

    public function test_proposal_number_already_exists_before_the_renderer_reads_it(): void
    {
        config(['aculyze.organization_identity' => [
            'legal_name' => 'Aculyze Solutions Pvt Ltd',
            'registered_address' => '123 Business Park, Bengaluru',
            'gstin' => '29ABCDE1234F1Z5',
        ]]);
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->submittedVersion($employee, $manager);

        app(ProposalVersionWorkflowService::class)->approve($version, $seniorManager);

        $proposal = $version->fresh()->proposal;
        $this->assertNotNull($proposal->proposal_number);

        $artifact = app(ProposalPdfArtifactService::class)->currentPrimary($version->fresh());
        $this->assertNotNull($artifact);

        $pdfText = $this->extractPdfText(Storage::disk('local')->get($artifact->storage_path));
        $this->assertStringContainsString($proposal->proposal_number, $pdfText);
    }

    public function test_successful_approval_auto_attempts_generation_after_commit(): void
    {
        config(['aculyze.organization_identity' => [
            'legal_name' => 'Aculyze Solutions Pvt Ltd',
            'registered_address' => '123 Business Park, Bengaluru',
            'gstin' => '29ABCDE1234F1Z5',
        ]]);
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->submittedVersion($employee, $manager);

        app(ProposalVersionWorkflowService::class)->approve($version, $seniorManager);

        $artifact = ProposalPdfArtifact::query()->where('proposal_version_id', $version->id)->first();
        $this->assertNotNull($artifact, 'No PDF artifact was auto-generated after approval commit.');
        $this->assertSame(ProposalPdfArtifactStatus::Success, $artifact->status);
        $this->assertSame($seniorManager->id, $artifact->generated_by);
    }

    public function test_failed_auto_generation_after_approval_still_records_permanent_failed_history(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->submittedVersion($employee, $manager);

        app(ProposalVersionWorkflowService::class)->approve($version, $seniorManager);

        $artifact = ProposalPdfArtifact::query()->where('proposal_version_id', $version->id)->first();
        $this->assertNotNull($artifact);
        $this->assertSame(ProposalPdfArtifactStatus::Failed, $artifact->status);
        $this->assertSame($seniorManager->id, $artifact->generated_by);
    }

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
