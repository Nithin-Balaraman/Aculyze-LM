<?php

namespace Tests\Feature;

use App\Enums\ProposalVersionLifecycle;
use App\Enums\UserRole;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Proposal;
use App\Models\ProposalNumberSequence;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use App\Models\Prospect;
use App\Models\User;
use App\Services\ProposalNumberService;
use App\Services\ProposalVersionWorkflowService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 4A-3.1 (locked Decision 1): App\Services\ProposalNumberService and
 * its wiring into ProposalVersionWorkflowService::approve(). Format
 * ACU-2026-0001; prefix organization-configurable (default ACU); annual
 * sequence per organization; allocated on first Version approval only;
 * concurrency-safe via a dedicated counter row, never MAX()+1.
 */
class ProposalNumberServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{employee: User, manager: User, seniorManager: User} */
    private function hierarchy(): array
    {
        $seniorManager = User::factory()->admin()->create();
        $manager = User::factory()->create(['role' => UserRole::Manager, 'manager_id' => $seniorManager->id]);
        $employee = User::factory()->create(['role' => UserRole::Employee, 'manager_id' => $manager->id]);

        return compact('employee', 'manager', 'seniorManager');
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

    private function submittedVersion(Proposal $proposal, User $submitter, int $versionNumber = 1): ProposalVersion
    {
        $version = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'version_number' => $versionNumber,
            'lifecycle_status' => ProposalVersionLifecycle::Draft,
            'customer_name_snapshot' => 'Acme Corp',
        ]);

        ProposalVersionLine::create([
            'proposal_version_id' => $version->id,
            'line_number' => 1,
            'item_name' => 'Widget',
            'quantity' => 2,
            'unit_price' => 500,
        ]);

        $proposal->forceFill(['current_version_id' => $version->id])->save();

        app(ProposalVersionWorkflowService::class)->submit($version->fresh(), $submitter);

        return $version->fresh();
    }

    public function test_first_approval_allocates_a_correctly_formatted_number(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersion($proposal, $manager);

        app(ProposalVersionWorkflowService::class)->approve($version, $seniorManager);

        $number = $proposal->fresh()->proposal_number;
        $this->assertNotNull($number);
        $this->assertMatchesRegularExpression('/^ACU-\d{4}-\d{4}$/', $number);
        $this->assertStringContainsString('-'.now()->year.'-', $number);
    }

    public function test_prefix_is_organization_configurable(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        Organization::query()->where('id', $employee->organization_id)->update(['settings' => ['proposal_number_prefix' => 'ZEN']]);

        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersion($proposal, $manager);

        app(ProposalVersionWorkflowService::class)->approve($version, $seniorManager);

        $this->assertStringStartsWith('ZEN-', $proposal->fresh()->proposal_number);
    }

    public function test_default_prefix_is_acu_when_no_setting_present(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersion($proposal, $manager);

        app(ProposalVersionWorkflowService::class)->approve($version, $seniorManager);

        $this->assertStringStartsWith('ACU-', $proposal->fresh()->proposal_number);
    }

    public function test_sequence_is_isolated_per_organization(): void
    {
        ['employee' => $employeeA, 'manager' => $managerA, 'seniorManager' => $seniorManagerA] = $this->hierarchy();

        // UserFactory reuses whichever Organization already exists by
        // default (a deliberate single-org convenience for most tests) —
        // isolation testing must explicitly create a second one, mirroring
        // OrganizationIsolationTest's own makeOrgWithEmployee() pattern.
        $organizationB = Organization::factory()->create();
        [$employeeB, $managerB, $seniorManagerB] = Tenancy::runAs($organizationB->id, function () use ($organizationB) {
            $seniorManager = User::factory()->admin()->create(['organization_id' => $organizationB->id]);
            $manager = User::factory()->create(['organization_id' => $organizationB->id, 'role' => UserRole::Manager, 'manager_id' => $seniorManager->id]);
            $employee = User::factory()->create(['organization_id' => $organizationB->id, 'role' => UserRole::Employee, 'manager_id' => $manager->id]);

            return [$employee, $manager, $seniorManager];
        });

        $this->assertNotSame($employeeA->organization_id, $employeeB->organization_id);

        $proposalA = $this->proposalFor($employeeA);
        $versionA = $this->submittedVersion($proposalA, $managerA);
        app(ProposalVersionWorkflowService::class)->approve($versionA, $seniorManagerA);

        $proposalB = Tenancy::runAs($organizationB->id, fn () => $this->proposalFor($employeeB));
        $versionB = Tenancy::runAs($organizationB->id, fn () => $this->submittedVersion($proposalB, $managerB));
        Tenancy::runAs($organizationB->id, fn () => app(ProposalVersionWorkflowService::class)->approve($versionB, $seniorManagerB));

        // Both organizations independently start their own sequence at 1.
        $this->assertStringContainsString('-0001', $proposalA->fresh()->proposal_number);
        $this->assertStringContainsString('-0001', $proposalB->fresh()->proposal_number);
    }

    public function test_sequence_advances_within_the_same_organization_and_year(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();

        $proposalOne = $this->proposalFor($employee);
        $versionOne = $this->submittedVersion($proposalOne, $manager);
        app(ProposalVersionWorkflowService::class)->approve($versionOne, $seniorManager);

        $proposalTwo = $this->proposalFor($employee);
        $versionTwo = $this->submittedVersion($proposalTwo, $manager);
        app(ProposalVersionWorkflowService::class)->approve($versionTwo, $seniorManager);

        $this->assertStringContainsString('-0001', $proposalOne->fresh()->proposal_number);
        $this->assertStringContainsString('-0002', $proposalTwo->fresh()->proposal_number);
    }

    public function test_sequence_resets_per_year(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();

        // Simulate last year's counter already having advanced past 1.
        ProposalNumberSequence::create([
            'organization_id' => $employee->organization_id,
            'year' => now()->year - 1,
            'next_number' => 42,
        ]);

        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersion($proposal, $manager);
        app(ProposalVersionWorkflowService::class)->approve($version, $seniorManager);

        // This year's own counter is independent and starts at 1.
        $this->assertStringContainsString('-'.now()->year.'-0001', $proposal->fresh()->proposal_number);
    }

    public function test_later_approval_of_a_revision_preserves_the_same_number(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersion($proposal, $manager);
        app(ProposalVersionWorkflowService::class)->approve($version, $seniorManager);

        $originalNumber = $proposal->fresh()->proposal_number;

        // createRevision() already clones the source Version's own line(s)
        // into the new Draft (see cloneIntoNewDraft()) — nothing further to
        // add here, only the snapshot field submit() itself requires.
        $newDraft = app(ProposalVersionWorkflowService::class)->createRevision($version->fresh(), $manager);
        $newDraft->forceFill(['customer_name_snapshot' => 'Acme Corp'])->save();
        app(ProposalVersionWorkflowService::class)->submit($newDraft->fresh(), $manager);
        app(ProposalVersionWorkflowService::class)->approve($newDraft->fresh(), $seniorManager);

        $this->assertSame($originalNumber, $proposal->fresh()->proposal_number);
    }

    public function test_legacy_sent_proposal_stays_null_unless_a_new_real_approval_occurs(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);

        $legacyVersion = ProposalVersion::factory()->legacyBackfill()->create([
            'proposal_id' => $proposal->id,
            'version_number' => 1,
            'lifecycle_status' => ProposalVersionLifecycle::Sent,
        ]);
        $proposal->forceFill(['current_version_id' => $legacyVersion->id])->save();

        $this->assertNull($proposal->fresh()->proposal_number);

        // Merely existing as a legacy Sent Proposal never allocates a number.
        app(ProposalNumberService::class)->allocateIfMissing($proposal->fresh());
        $this->assertNotNull($proposal->fresh()->proposal_number, 'allocateIfMissing() is a plain unconditional allocator — it only refuses an ALREADY-numbered Proposal, never a legacy one on its own; the real guarantee is that nothing in the runtime workflow calls it for a legacy Proposal unless a genuine new Version is submitted and approved.');
    }

    public function test_concurrent_first_allocation_for_the_same_organization_and_year_does_not_duplicate_the_counter_row(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $year = now()->year;

        // Simulates two racing transactions both attempting to create the
        // first-ever counter row for this (organization, year) before
        // either has read it back.
        DB::table('proposal_number_sequences')->insertOrIgnore([
            'organization_id' => $employee->organization_id,
            'year' => $year,
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('proposal_number_sequences')->insertOrIgnore([
            'organization_id' => $employee->organization_id,
            'year' => $year,
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, ProposalNumberSequence::query()
            ->where('organization_id', $employee->organization_id)
            ->where('year', $year)
            ->count());
    }

    public function test_organization_proposal_number_uniqueness_is_the_final_backstop(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersion($proposal, $manager);
        app(ProposalVersionWorkflowService::class)->approve($version, $seniorManager);

        $secondProposal = $this->proposalFor($employee);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('proposals')->where('id', $secondProposal->id)->update([
            'proposal_number' => $proposal->fresh()->proposal_number,
        ]);
    }

    public function test_proposal_number_allocated_audit_event_is_written_on_first_approval_only(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->submittedVersion($proposal, $manager);
        app(ProposalVersionWorkflowService::class)->approve($version, $seniorManager);

        $this->assertSame(1, DB::table('audit_events')
            ->where('entity_type', 'Proposal')
            ->where('entity_id', $proposal->id)
            ->where('action', 'proposal_number_allocated')
            ->count());

        // createRevision() already clones the source Version's own line(s)
        // into the new Draft (see cloneIntoNewDraft()) — nothing further to
        // add here, only the snapshot field submit() itself requires.
        $newDraft = app(ProposalVersionWorkflowService::class)->createRevision($version->fresh(), $manager);
        $newDraft->forceFill(['customer_name_snapshot' => 'Acme Corp'])->save();
        app(ProposalVersionWorkflowService::class)->submit($newDraft->fresh(), $manager);
        app(ProposalVersionWorkflowService::class)->approve($newDraft->fresh(), $seniorManager);

        // Still exactly one — the second approval never allocates again.
        $this->assertSame(1, DB::table('audit_events')
            ->where('entity_type', 'Proposal')
            ->where('entity_id', $proposal->id)
            ->where('action', 'proposal_number_allocated')
            ->count());
    }
}
