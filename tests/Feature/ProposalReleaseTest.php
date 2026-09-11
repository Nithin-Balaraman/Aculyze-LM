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
use App\Policies\ProposalReleasePolicy;
use App\Services\ProposalPdfArtifactService;
use App\Services\ProposalReleaseService;
use App\Services\ProposalVersionWorkflowService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

/**
 * Phase 4A-3.3, Section C/D: ProposalReleaseService and the derived
 * staleness rule. Release binds the exact current successful primary PDF
 * artifact for client sending — never a second commercial approval, never
 * a lifecycle value, never a Proposal outcome.
 */
class ProposalReleaseTest extends TestCase
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
        $manager = User::factory()->create(['role' => UserRole::Manager, 'manager_id' => $seniorManager->id]);
        $employee = User::factory()->create(['role' => UserRole::Employee, 'manager_id' => $manager->id]);

        return compact('employee', 'manager', 'seniorManager');
    }

    /** Builds and approves a fresh commercial Version (auto-PDF-generation fires via DB::afterCommit() during approve()). */
    private function approvedVersion(User $employee, User $submitter, User $approver): ProposalVersion
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
        $proposal->forceFill(['current_version_id' => $version->id])->save();

        app(ProposalVersionWorkflowService::class)->submit($version->fresh(), $submitter);
        app(ProposalVersionWorkflowService::class)->approve($version->fresh(), $approver);

        return $version->fresh(['proposal']);
    }

    public function test_manager_can_release_eligible_approved_current_version(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->approvedVersion($employee, $manager, $seniorManager);

        $released = app(ProposalReleaseService::class)->release($version, $manager, 'Ready to send.');

        $this->assertNotNull($released->released_at);
        $this->assertSame($manager->id, $released->released_by);
        $this->assertSame('Ready to send.', $released->release_comment);
    }

    public function test_senior_manager_can_release(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->approvedVersion($employee, $manager, $seniorManager);

        $released = app(ProposalReleaseService::class)->release($version, $seniorManager);

        $this->assertNotNull($released->released_at);
        $this->assertSame($seniorManager->id, $released->released_by);
    }

    public function test_employee_cannot_release(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->approvedVersion($employee, $manager, $seniorManager);

        $this->expectException(LogicException::class);

        app(ProposalReleaseService::class)->release($version, $employee);
    }

    public function test_cross_tenant_release_is_denied(): void
    {
        ['employee' => $employeeA, 'manager' => $managerA, 'seniorManager' => $seniorManagerA] = $this->hierarchy();
        $versionA = $this->approvedVersion($employeeA, $managerA, $seniorManagerA);

        $organizationB = Organization::factory()->create();
        $managerB = Tenancy::runAs($organizationB->id, fn () => User::factory()->create([
            'organization_id' => $organizationB->id, 'role' => UserRole::Manager,
        ]));

        $this->assertFalse(app(ProposalReleasePolicy::class)->release($managerB, $versionA->fresh(['proposal'])));
    }

    public function test_legacy_version_cannot_release(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->approvedVersion($employee, $manager, $seniorManager);
        $version->forceFill(['is_legacy_backfill' => true])->saveQuietly();

        $this->expectException(LogicException::class);

        app(ProposalReleaseService::class)->release($version->fresh(['proposal']), $manager);
    }

    public function test_non_current_version_cannot_release(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->approvedVersion($employee, $manager, $seniorManager);

        app(ProposalVersionWorkflowService::class)->createRevision($version->fresh(), $manager);

        $this->expectException(LogicException::class);

        app(ProposalReleaseService::class)->release($version->fresh(['proposal']), $manager);
    }

    public function test_release_binds_exact_current_primary_artifact(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->approvedVersion($employee, $manager, $seniorManager);
        $currentPrimary = app(ProposalPdfArtifactService::class)->currentPrimary($version->fresh());

        $released = app(ProposalReleaseService::class)->release($version->fresh(), $manager);

        $this->assertSame($currentPrimary->getKey(), $released->released_pdf_artifact_id);
    }

    public function test_release_without_current_successful_primary_is_blocked(): void
    {
        // No identity configured at all — approval's auto-generation fails,
        // leaving no Success artifact.
        config(['aculyze.organization_identity' => []]);

        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->approvedVersion($employee, $manager, $seniorManager);

        $this->assertNull(app(ProposalPdfArtifactService::class)->currentPrimary($version->fresh()));

        $this->expectException(LogicException::class);

        app(ProposalReleaseService::class)->release($version->fresh(), $manager);
    }

    public function test_sent_version_can_be_re_released(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->approvedVersion($employee, $manager, $seniorManager);
        $version->forceFill(['lifecycle_status' => ProposalVersionLifecycle::Sent])->save();

        $released = app(ProposalReleaseService::class)->release($version->fresh(), $manager, 'Re-release while Sent.');

        $this->assertNotNull($released->released_at);
    }

    public function test_pdf_correction_makes_the_old_release_stale(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->approvedVersion($employee, $manager, $seniorManager);
        app(ProposalReleaseService::class)->release($version->fresh(), $manager);

        $this->assertFalse($version->fresh()->isReleaseStale());

        app(ProposalPdfArtifactService::class)->correct($version->fresh(), $manager, 'Correction after release.');

        $this->assertTrue($version->fresh()->isReleaseStale());
    }

    public function test_stale_release_blocks_employee_download(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->approvedVersion($employee, $manager, $seniorManager);
        app(ProposalReleaseService::class)->release($version->fresh(), $manager);

        $this->assertTrue(app(\App\Policies\ProposalPdfArtifactPolicy::class)->downloadPdf($employee, $version->fresh(['proposal'])));

        app(ProposalPdfArtifactService::class)->correct($version->fresh(), $manager, 'Correction after release.');

        $this->assertFalse(app(\App\Policies\ProposalPdfArtifactPolicy::class)->downloadPdf($employee, $version->fresh(['proposal'])));
    }

    public function test_re_release_restores_employee_download_eligibility(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->approvedVersion($employee, $manager, $seniorManager);
        app(ProposalReleaseService::class)->release($version->fresh(), $manager);
        app(ProposalPdfArtifactService::class)->correct($version->fresh(), $manager, 'Correction after release.');

        $this->assertFalse(app(\App\Policies\ProposalPdfArtifactPolicy::class)->downloadPdf($employee, $version->fresh(['proposal'])));

        app(ProposalReleaseService::class)->release($version->fresh(), $manager, 'Re-release after correction.');

        $this->assertFalse($version->fresh()->isReleaseStale());
        $this->assertTrue(app(\App\Policies\ProposalPdfArtifactPolicy::class)->downloadPdf($employee, $version->fresh(['proposal'])));
    }

    public function test_release_does_not_alter_lifecycle_or_outcome(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->approvedVersion($employee, $manager, $seniorManager);
        $proposalBefore = $version->fresh()->proposal;

        app(ProposalReleaseService::class)->release($version->fresh(), $manager, 'Comment.');

        $freshVersion = $version->fresh();
        $freshProposal = $freshVersion->proposal;

        $this->assertSame(ProposalVersionLifecycle::Approved, $freshVersion->lifecycle_status);
        $this->assertSame($proposalBefore->stage->value, $freshProposal->stage->value);
        $this->assertNull($freshProposal->outcome);
    }
}
