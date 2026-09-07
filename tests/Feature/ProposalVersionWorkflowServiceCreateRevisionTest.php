<?php

namespace Tests\Feature;

use App\Enums\ProposalVersionLifecycle;
use App\Enums\UserRole;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use App\Models\Prospect;
use App\Models\User;
use App\Services\ProposalVersionWorkflowService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Phase 4A-2.2: ProposalVersionWorkflowService::createRevision() —
 * Approved or Sent (including legacy backfilled Sent) -> a new Draft,
 * Manager-or-above (Employee excluded). The old Version's lifecycle_status
 * is never rewritten. Also covers concurrency-safe version_number
 * allocation (locked principle: the Proposal row lock serializes every
 * Version-creation transition for the same Proposal).
 */
class ProposalVersionWorkflowServiceCreateRevisionTest extends TestCase
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
            'notes' => 'Validated in test fixture.',
        ]);

        return Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => 'being_prepared',
        ]);
    }

    private function currentVersion(Proposal $proposal, ProposalVersionLifecycle $lifecycle, array $overrides = []): ProposalVersion
    {
        $version = ProposalVersion::factory()->create(array_merge([
            'proposal_id' => $proposal->id,
            'version_number' => 1,
            'lifecycle_status' => $lifecycle,
            'customer_name_snapshot' => 'Acme Corp',
        ], $overrides));

        $proposal->forceFill(['current_version_id' => $version->id])->save();

        return $version->fresh();
    }

    public function test_authorized_manager_can_create_revision_from_current_approved(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->currentVersion($proposal, ProposalVersionLifecycle::Approved);

        $newDraft = app(ProposalVersionWorkflowService::class)->createRevision($version, $manager);

        $this->assertSame(ProposalVersionLifecycle::Draft, $newDraft->lifecycle_status);
    }

    public function test_authorized_manager_can_create_revision_from_current_sent(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->currentVersion($proposal, ProposalVersionLifecycle::Sent);

        $newDraft = app(ProposalVersionWorkflowService::class)->createRevision($version, $manager);

        $this->assertSame(ProposalVersionLifecycle::Draft, $newDraft->lifecycle_status);
    }

    public function test_senior_manager_can_create_revision_from_approved_or_sent(): void
    {
        ['employee' => $employee, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->currentVersion($proposal, ProposalVersionLifecycle::Sent);

        $newDraft = app(ProposalVersionWorkflowService::class)->createRevision($version, $seniorManager);

        $this->assertSame(ProposalVersionLifecycle::Draft, $newDraft->lifecycle_status);
    }

    public function test_employee_cannot_create_revision(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->currentVersion($proposal, ProposalVersionLifecycle::Approved);

        $this->expectException(LogicException::class);
        app(ProposalVersionWorkflowService::class)->createRevision($version, $employee);
    }

    public function test_out_of_hierarchy_manager_cannot_create_revision(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->currentVersion($proposal, ProposalVersionLifecycle::Approved);

        $unrelatedSeniorManager = User::factory()->admin()->create(['organization_id' => $employee->organization_id]);
        $unrelatedManager = User::factory()->create([
            'role' => UserRole::Manager,
            'manager_id' => $unrelatedSeniorManager->id,
            'organization_id' => $employee->organization_id,
        ]);

        $this->expectException(LogicException::class);
        app(ProposalVersionWorkflowService::class)->createRevision($version, $unrelatedManager);
    }

    public function test_non_current_source_cannot_create_revision(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->currentVersion($proposal, ProposalVersionLifecycle::Approved);

        $otherVersion = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'version_number' => 2,
            'lifecycle_status' => ProposalVersionLifecycle::Sent,
        ]);
        $proposal->forceFill(['current_version_id' => $otherVersion->id])->save();

        $this->expectException(LogicException::class);
        app(ProposalVersionWorkflowService::class)->createRevision($version, $manager);
    }

    public function test_draft_cannot_use_create_revision(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->currentVersion($proposal, ProposalVersionLifecycle::Draft);

        $this->expectException(LogicException::class);
        app(ProposalVersionWorkflowService::class)->createRevision($version, $manager);
    }

    public function test_submitted_cannot_use_manager_create_revision(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->currentVersion($proposal, ProposalVersionLifecycle::Submitted);

        $this->expectException(LogicException::class);
        app(ProposalVersionWorkflowService::class)->createRevision($version, $manager);
    }

    public function test_returned_for_revision_cannot_use_manual_create_revision(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->currentVersion($proposal, ProposalVersionLifecycle::ReturnedForRevision);

        $this->expectException(LogicException::class);
        app(ProposalVersionWorkflowService::class)->createRevision($version, $manager);
    }

    public function test_old_approved_lifecycle_remains_approved(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->currentVersion($proposal, ProposalVersionLifecycle::Approved);

        app(ProposalVersionWorkflowService::class)->createRevision($version, $manager);

        $this->assertSame(ProposalVersionLifecycle::Approved, $version->fresh()->lifecycle_status);
    }

    public function test_old_sent_lifecycle_remains_sent(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->currentVersion($proposal, ProposalVersionLifecycle::Sent);

        app(ProposalVersionWorkflowService::class)->createRevision($version, $manager);

        $this->assertSame(ProposalVersionLifecycle::Sent, $version->fresh()->lifecycle_status);
    }

    public function test_superseded_metadata_points_to_new_draft(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->currentVersion($proposal, ProposalVersionLifecycle::Approved);

        $newDraft = app(ProposalVersionWorkflowService::class)->createRevision($version, $manager);

        $fresh = $version->fresh();
        $this->assertNotNull($fresh->superseded_at);
        $this->assertSame($newDraft->id, $fresh->superseded_by_version_id);
    }

    public function test_current_version_id_moves_to_new_draft(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->currentVersion($proposal, ProposalVersionLifecycle::Approved);

        $newDraft = app(ProposalVersionWorkflowService::class)->createRevision($version, $manager);

        $this->assertSame($newDraft->id, $proposal->fresh()->current_version_id);
    }

    public function test_source_snapshot_lines_and_taxes_clone_exactly(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        // Line must be added while still Draft (4A-2.1's immutability guard
        // rejects adding a line to an already-Approved parent) — build the
        // fixture as Draft, add the line, then transition to Approved.
        $version = $this->currentVersion($proposal, ProposalVersionLifecycle::Draft, [
            'customer_gstin_snapshot' => '33AAAAA0000A1Z5',
        ]);
        ProposalVersionLine::create([
            'proposal_version_id' => $version->id,
            'line_number' => 1,
            'item_name' => 'Widget',
            'quantity' => 3,
            'unit_price' => 250,
        ]);
        $version->forceFill(['lifecycle_status' => ProposalVersionLifecycle::Approved])->save();

        $newDraft = app(ProposalVersionWorkflowService::class)->createRevision($version->fresh(), $manager);

        $this->assertSame('Acme Corp', $newDraft->customer_name_snapshot);
        $this->assertSame('33AAAAA0000A1Z5', $newDraft->customer_gstin_snapshot);
        $newLine = $newDraft->lines()->first();
        $this->assertSame('Widget', $newLine->item_name);
        $this->assertEquals(3, $newLine->quantity);
    }

    public function test_source_workflow_evidence_does_not_clone(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->currentVersion($proposal, ProposalVersionLifecycle::Approved, [
            'approved_by' => $seniorManager->id,
            'approved_at' => now(),
            'approval_comment' => 'Fine.',
        ]);

        $newDraft = app(ProposalVersionWorkflowService::class)->createRevision($version, $manager);

        $this->assertNull($newDraft->approved_by);
        $this->assertNull($newDraft->approved_at);
        $this->assertNull($newDraft->approval_comment);
        $this->assertNull($newDraft->submitted_by);
    }

    /**
     * The exact real production shape: a legacy Sent Version with blank
     * customer/commercial snapshot fields and zero lines — Create
     * Revision must work unchanged, truthfully cloning the blanks/zero
     * lines rather than fabricating anything.
     */
    public function test_legacy_sent_source_works_without_special_fabricated_data(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'version_number' => 1,
            'lifecycle_status' => ProposalVersionLifecycle::Sent,
            'is_legacy_backfill' => true,
            'customer_name_snapshot' => null,
            'payment_terms' => null,
            'validity_terms' => null,
            'scope_notes' => null,
        ]);
        $proposal->forceFill(['current_version_id' => $version->id])->save();

        $newDraft = app(ProposalVersionWorkflowService::class)->createRevision($version->fresh(), $manager);

        $this->assertSame(ProposalVersionLifecycle::Draft, $newDraft->lifecycle_status);
        $this->assertFalse($newDraft->is_legacy_backfill);
        $this->assertNull($newDraft->customer_name_snapshot);
        $this->assertNull($newDraft->payment_terms);
        $this->assertSame(ProposalVersionLifecycle::Sent, $version->fresh()->lifecycle_status);
        $this->assertTrue($version->fresh()->is_legacy_backfill);
        $this->assertDatabaseCount('proposal_version_lines', 0);
    }

    public function test_repeated_double_create_revision_does_not_create_duplicate_draft(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->currentVersion($proposal, ProposalVersionLifecycle::Approved);

        app(ProposalVersionWorkflowService::class)->createRevision($version, $manager);

        try {
            app(ProposalVersionWorkflowService::class)->createRevision($version, $manager);
            $this->fail('Expected a second Create Revision against the same, now-superseded source to be rejected.');
        } catch (LogicException) {
            // expected
        }

        $this->assertSame(2, DB::table('proposal_versions')->where('proposal_id', $proposal->id)->count());
    }

    public function test_create_revision_writes_a_revision_created_audit_event(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->currentVersion($proposal, ProposalVersionLifecycle::Approved);

        $newDraft = app(ProposalVersionWorkflowService::class)->createRevision($version, $manager);

        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'ProposalVersion',
            'entity_id' => $newDraft->id,
            'action' => 'proposal_version_revision_created',
        ]);
    }

    public function test_create_revision_never_touches_parent_proposal_outcome_or_winning_version(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->currentVersion($proposal, ProposalVersionLifecycle::Approved);

        app(ProposalVersionWorkflowService::class)->createRevision($version, $manager);

        $fresh = $proposal->fresh();
        $this->assertNull($fresh->outcome);
        $this->assertNull($fresh->winning_version_id);
    }

    // ── E. Version number allocation / transaction safety ────────────────

    /** A gap in version_number history (e.g. V1, V3 exist, V2 doesn't) must still allocate MAX+1 correctly. */
    public function test_existing_gaps_in_version_number_history_still_allocate_max_plus_one_correctly(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->currentVersion($proposal, ProposalVersionLifecycle::Approved);

        // Simulate a gap: a superseded (non-current) V3 already exists, V2 never did.
        DB::table('proposal_versions')->insert([
            'organization_id' => $proposal->organization_id,
            'proposal_id' => $proposal->id,
            'version_number' => 3,
            'lifecycle_status' => 'approved',
            'is_legacy_backfill' => false,
            'currency_code' => 'INR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $newDraft = app(ProposalVersionWorkflowService::class)->createRevision($version, $manager);

        $this->assertSame(4, $newDraft->version_number);
    }

    public function test_unique_version_number_invariant_remains_satisfied(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->currentVersion($proposal, ProposalVersionLifecycle::Approved);

        app(ProposalVersionWorkflowService::class)->createRevision($version, $manager);

        $this->expectException(QueryException::class);
        DB::table('proposal_versions')->insert([
            'organization_id' => $proposal->organization_id,
            'proposal_id' => $proposal->id,
            'version_number' => 2,
            'lifecycle_status' => 'approved',
            'is_legacy_backfill' => false,
            'currency_code' => 'INR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_draft_lock_key_invariant_remains_satisfied(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->currentVersion($proposal, ProposalVersionLifecycle::Approved);

        app(ProposalVersionWorkflowService::class)->createRevision($version, $manager);

        $this->expectException(QueryException::class);
        DB::table('proposal_versions')->insert([
            'organization_id' => $proposal->organization_id,
            'proposal_id' => $proposal->id,
            'version_number' => 5,
            'lifecycle_status' => 'draft',
            'is_legacy_backfill' => false,
            'currency_code' => 'INR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * State-revalidation/idempotency proof — this codebase's tests cannot
     * exercise true parallel DB transactions, so this proves the
     * revalidate-after-lock behavior the locking relies on: a "concurrent"
     * second attempt (simulated sequentially, since the first already
     * committed by the time this runs) must re-read state and fail
     * cleanly rather than silently retrying into a duplicate success. This
     * is NOT a proof of true parallel-transaction blocking.
     */
    public function test_state_revalidation_after_lock_rejects_a_simulated_concurrent_second_attempt(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->currentVersion($proposal, ProposalVersionLifecycle::Approved);

        // "Manager B" loads the same page/record before "Manager A" acts —
        // both now hold an in-memory ProposalVersion fetched at the same
        // (still-Approved) state.
        $managerBsStaleCopy = ProposalVersion::find($version->id);

        // "Manager A's" request completes first.
        app(ProposalVersionWorkflowService::class)->createRevision($version, $manager);

        // "Manager B's" request re-locks and re-reads inside the service —
        // it must reject based on the NOW-current DB state, never trusting
        // the stale in-memory copy it was holding.
        $this->expectException(LogicException::class);
        app(ProposalVersionWorkflowService::class)->createRevision($managerBsStaleCopy, $manager);
    }

    public function test_failure_during_validation_leaves_source_and_current_version_id_unchanged_and_creates_no_partial_draft(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->currentVersion($proposal, ProposalVersionLifecycle::Draft);
        $originalCurrentVersionId = $proposal->current_version_id;

        try {
            app(ProposalVersionWorkflowService::class)->createRevision($version, $manager);
            $this->fail('Expected Create Revision against a Draft source to be rejected.');
        } catch (LogicException) {
            // expected
        }

        $this->assertSame($originalCurrentVersionId, $proposal->fresh()->current_version_id);
        $this->assertNull($version->fresh()->superseded_at);
        $this->assertSame(1, DB::table('proposal_versions')->where('proposal_id', $proposal->id)->count());
    }
}
