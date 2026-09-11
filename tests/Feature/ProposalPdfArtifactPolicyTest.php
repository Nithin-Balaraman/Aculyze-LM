<?php

namespace Tests\Feature;

use App\Enums\ProposalVersionLifecycle;
use App\Enums\UserRole;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use App\Models\Prospect;
use App\Models\User;
use App\Policies\ProposalPdfArtifactPolicy;
use App\Services\ProposalPdfArtifactService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 4A-3.2, Section O: authenticated, policy-gated review/download
 * only. Manager/Senior Manager may generate/correct/download, hierarchy/
 * tenant scoped; Employee has NO final-PDF ability at all in 4A-3.2 (no
 * Release exists yet — locked Decision 5).
 */
class ProposalPdfArtifactPolicyTest extends TestCase
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

    /** @return array{employee: User, manager: User, seniorManager: User} */
    private function hierarchy(): array
    {
        $seniorManager = User::factory()->admin()->create();
        $manager = User::factory()->create(['role' => UserRole::Manager, 'manager_id' => $seniorManager->id, 'organization_id' => $seniorManager->organization_id]);
        $employee = User::factory()->create(['role' => UserRole::Employee, 'manager_id' => $manager->id, 'organization_id' => $seniorManager->organization_id]);

        return compact('employee', 'manager', 'seniorManager');
    }

    private function approvedVersionFor(User $employee): ProposalVersion
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

        return $version->fresh(['proposal']);
    }

    public function test_manager_is_authorized_to_generate_and_download(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $version = $this->approvedVersionFor($employee);

        $this->assertTrue(app(ProposalPdfArtifactPolicy::class)->generatePdf($manager, $version));

        app(ProposalPdfArtifactService::class)->generateIfMissing($version, $manager);
        $this->assertTrue(app(ProposalPdfArtifactPolicy::class)->downloadPdf($manager, $version->fresh()));
    }

    public function test_senior_manager_is_authorized_organization_wide(): void
    {
        ['employee' => $employee, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->approvedVersionFor($employee);

        $this->assertTrue(app(ProposalPdfArtifactPolicy::class)->generatePdf($seniorManager, $version));
        $this->assertTrue(app(ProposalPdfArtifactPolicy::class)->correctPdf($seniorManager, $version));
        $this->assertTrue(app(ProposalPdfArtifactPolicy::class)->downloadPdf($seniorManager, $version));
    }

    public function test_employee_cannot_generate_correct_or_download(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $version = $this->approvedVersionFor($employee);
        app(ProposalPdfArtifactService::class)->generateIfMissing($version, $manager);

        $this->assertFalse(app(ProposalPdfArtifactPolicy::class)->generatePdf($employee, $version->fresh()));
        $this->assertFalse(app(ProposalPdfArtifactPolicy::class)->correctPdf($employee, $version->fresh()));
        $this->assertFalse(app(ProposalPdfArtifactPolicy::class)->downloadPdf($employee, $version->fresh()));
    }

    public function test_cross_tenant_access_is_denied(): void
    {
        ['employee' => $employeeA, 'manager' => $managerA] = $this->hierarchy();
        $versionA = $this->approvedVersionFor($employeeA);
        app(ProposalPdfArtifactService::class)->generateIfMissing($versionA, $managerA);

        $organizationB = Organization::factory()->create();
        $managerB = Tenancy::runAs($organizationB->id, fn () => User::factory()->create([
            'organization_id' => $organizationB->id, 'role' => UserRole::Manager,
        ]));

        $this->assertFalse(app(ProposalPdfArtifactPolicy::class)->downloadPdf($managerB, $versionA->fresh()));
        $this->assertFalse(app(ProposalPdfArtifactPolicy::class)->generatePdf($managerB, $versionA->fresh()));
    }

    public function test_historical_superseded_artifact_download_authorization_is_enforced(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $version = $this->approvedVersionFor($employee);
        $original = app(ProposalPdfArtifactService::class)->generateIfMissing($version, $manager);
        $corrected = app(ProposalPdfArtifactService::class)->correct($version->fresh(), $manager, 'Correction.');

        // The now-superseded original artifact is still downloadable by an
        // authorized Manager/Senior Manager (Section O) — authorization is
        // checked against the VERSION, not the artifact's own current/
        // superseded state.
        $this->assertTrue(app(ProposalPdfArtifactPolicy::class)->downloadPdf($manager, $version->fresh()));
        $this->assertNotNull($original->fresh()->superseded_at);
        $this->assertTrue(Storage::disk('local')->exists($original->fresh()->storage_path));

        ['employee' => $otherEmployee] = $this->hierarchy();
        $this->assertFalse(app(ProposalPdfArtifactPolicy::class)->downloadPdf($otherEmployee, $version->fresh()));
    }
}
