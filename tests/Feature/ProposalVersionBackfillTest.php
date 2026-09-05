<?php

namespace Tests\Feature;

use App\Enums\ProposalOutcome;
use App\Enums\ProposalStage;
use App\Enums\ProposalVersionLifecycle;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Phase 4A-1: App\Console\Commands\BackfillProposalVersions, exercised
 * against the two confirmed production stage/outcome combinations
 * (Fixture A: being_prepared+NULL, Fixture B: sent+hold — see the Phase 4A
 * production audit). No synthetic Hold-response evidence, no fabricated
 * line items, legacy attachments and Proposal.value left untouched,
 * idempotent, dry-run writes nothing, and any unsupported combination halts
 * the entire run rather than being silently migrated.
 */
class ProposalVersionBackfillTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, string>|null  $attachmentPaths
     */
    private function proposal(
        User $owner,
        ProposalStage $stage,
        ?ProposalOutcome $outcome,
        ?float $value,
        ?array $attachmentPaths = null,
    ): Proposal {
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);

        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'stage' => 'validated',
            'temperature' => 'hot',
            'notes' => 'Validated in test fixture.',
        ]);

        return Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'stage' => $stage,
            'outcome' => $outcome,
            'value' => $value,
            'sent_at' => $stage === ProposalStage::Sent ? now()->subDays(3) : null,
            'notes' => $outcome !== null ? 'Client placed this on hold in test fixture.' : null,
            'attachment_paths' => $attachmentPaths,
            'attachment_names' => $attachmentPaths === null ? null : collect($attachmentPaths)->mapWithKeys(fn (string $path) => [$path => basename($path)])->all(),
        ]);
    }

    public function test_backfill_creates_a_draft_v1_for_a_being_prepared_null_outcome_proposal(): void
    {
        $owner = User::factory()->create();
        $proposal = $this->proposal($owner, ProposalStage::BeingPrepared, null, 50000.00);

        Artisan::call('aculyze:backfill-proposal-versions');

        $version = $proposal->fresh()->currentVersion;

        $this->assertNotNull($version);
        $this->assertSame(1, $version->version_number);
        $this->assertSame(ProposalVersionLifecycle::Draft, $version->lifecycle_status);
        $this->assertTrue($version->is_legacy_backfill);
        $this->assertSame('50000.00', $version->grand_total);
        $this->assertNull($version->sent_at);
        $this->assertNull($version->manager_reviewed_by);
        $this->assertNull($version->approved_by);
        $this->assertNull($proposal->fresh()->winning_version_id);
    }

    public function test_backfill_creates_a_sent_v1_for_a_sent_hold_proposal_with_no_synthetic_response_evidence(): void
    {
        $owner = User::factory()->create();
        $proposal = $this->proposal($owner, ProposalStage::Sent, ProposalOutcome::Hold, 75000.00);
        $originalSentAt = $proposal->sent_at;

        Artisan::call('aculyze:backfill-proposal-versions');

        $version = $proposal->fresh()->currentVersion;

        $this->assertNotNull($version);
        $this->assertSame(ProposalVersionLifecycle::Sent, $version->lifecycle_status);
        $this->assertTrue($version->is_legacy_backfill);
        $this->assertSame('75000.00', $version->grand_total);
        $this->assertTrue($originalSentAt->isSameDay($version->sent_at));

        // Locked "Option 1" decision: no synthetic Hold-response row or
        // approval/return evidence is ever fabricated for a legacy version.
        $this->assertNull($version->manager_reviewed_by);
        $this->assertNull($version->manager_reviewed_at);
        $this->assertNull($version->approved_by);
        $this->assertNull($version->approved_at);
        $this->assertNull($version->returned_by);
        $this->assertNull($version->returned_at);
        $this->assertDatabaseCount('proposal_version_lines', 0);
    }

    public function test_backfill_keeps_a_null_legacy_value_as_null_grand_total_never_zero(): void
    {
        $owner = User::factory()->create();
        $proposal = $this->proposal($owner, ProposalStage::BeingPrepared, null, null);

        Artisan::call('aculyze:backfill-proposal-versions');

        $this->assertNull($proposal->fresh()->currentVersion->grand_total);
    }

    public function test_backfill_does_not_touch_legacy_attachments_or_the_legacy_value_column(): void
    {
        $owner = User::factory()->create();
        $proposal = $this->proposal(
            $owner,
            ProposalStage::Sent,
            ProposalOutcome::Hold,
            30000.00,
            attachmentPaths: ['proposal-attachments/legacy.pdf'],
        );

        Artisan::call('aculyze:backfill-proposal-versions');

        $fresh = $proposal->fresh();
        $this->assertSame(['proposal-attachments/legacy.pdf'], $fresh->attachment_paths);
        $this->assertSame('30000.00', (string) $fresh->value);
    }

    public function test_backfill_is_idempotent_running_twice_creates_exactly_one_version_per_proposal(): void
    {
        $owner = User::factory()->create();
        $this->proposal($owner, ProposalStage::BeingPrepared, null, 10000.00);
        $this->proposal($owner, ProposalStage::Sent, ProposalOutcome::Hold, 20000.00);

        Artisan::call('aculyze:backfill-proposal-versions');
        $this->assertDatabaseCount('proposal_versions', 2);

        Artisan::call('aculyze:backfill-proposal-versions');
        $this->assertDatabaseCount('proposal_versions', 2);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $owner = User::factory()->create();
        $this->proposal($owner, ProposalStage::BeingPrepared, null, 10000.00);

        Artisan::call('aculyze:backfill-proposal-versions', ['--dry-run' => true]);

        $this->assertDatabaseCount('proposal_versions', 0);
        $this->assertDatabaseCount('proposals', 1);
        $this->assertDatabaseHas('proposals', ['current_version_id' => null]);
    }

    public function test_an_unsupported_stage_outcome_combination_halts_the_entire_run_without_writing_anything(): void
    {
        $owner = User::factory()->create();
        // A legitimate combination that is nonetheless NOT one of the two
        // confirmed production combinations must never be silently migrated.
        $unsupported = $this->proposal($owner, ProposalStage::Sent, ProposalOutcome::Lost, 15000.00);
        $supported = $this->proposal($owner, ProposalStage::BeingPrepared, null, 10000.00);

        $exitCode = Artisan::call('aculyze:backfill-proposal-versions');

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseCount('proposal_versions', 0);
        $this->assertNull($unsupported->fresh()->current_version_id);
        $this->assertNull($supported->fresh()->current_version_id);
    }
}
