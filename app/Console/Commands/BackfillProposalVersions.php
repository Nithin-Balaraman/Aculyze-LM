<?php

namespace App\Console\Commands;

use App\Enums\ProposalVersionLifecycle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4A-1: creates exactly one legacy V1 ProposalVersion for every
 * pre-Phase-4 Proposal that has none yet (current_version_id IS NULL), using
 * ONLY the two stage/outcome combinations the Phase 4A production audit
 * actually confirmed exist in production:
 *
 *   - stage=being_prepared, outcome=NULL   -> lifecycle_status=Draft
 *   - stage=sent,           outcome=hold   -> lifecycle_status=Sent
 *
 * Any Proposal whose stage/outcome pair is NOT one of these two combinations
 * is treated as an anomaly and the ENTIRE run is refused (no row anywhere is
 * changed) — this command must never guess a mapping for a combination the
 * business has not confirmed (Master BA Specification Principle P8, "no
 * fabricated history"). Broaden KNOWN_COMBINATIONS only after explicit
 * business sign-off, never speculatively.
 *
 * No fabricated evidence: is_legacy_backfill=true always, and
 * manager_reviewed_by/approved_by/returned_by/*_at/*_comment are always left
 * NULL — this includes the sent+hold combination, per the locked "Option 1"
 * decision that no synthetic Hold-response row or evidence is ever created.
 * grand_total is set from the legacy Proposal.value verbatim, including
 * staying NULL when Proposal.value is NULL (never coerced to zero). No
 * proposal_version_lines rows are created (no line-item history exists to
 * preserve). Proposal.value, attachment_paths and attachment_names are never
 * touched by this command.
 *
 * Uses raw DB::table() query-builder calls throughout, never Eloquent models
 * — mirrors BackfillOrganizations/BackfillLeadAppointmentStatus's own
 * precedent, and avoids needing the Tenancy::withoutScopeForSystemTask()
 * bypass mechanism entirely (see tests/Feature/TenancyBypassUsageTest.php,
 * which restricts OrganizationScope-bypassing calls to Tenancy.php alone).
 *
 * Idempotent: only Proposals with current_version_id IS NULL are considered,
 * and current_version_id is set atomically with its V1 row inside the same
 * transaction, so re-running this command after a successful run is a no-op.
 * winning_version_id is never touched here — neither confirmed production
 * combination is outcome=Won.
 */
class BackfillProposalVersions extends Command
{
    protected $signature = 'aculyze:backfill-proposal-versions {--dry-run : Report what would happen without writing anything}';

    protected $description = 'Backfill a legacy V1 ProposalVersion for every pre-Phase-4 Proposal using only the confirmed production stage/outcome combinations';

    /**
     * @var array<int, array{stage: string, outcome: ?string, lifecycle: ProposalVersionLifecycle}>
     */
    private const KNOWN_COMBINATIONS = [
        ['stage' => 'being_prepared', 'outcome' => null, 'lifecycle' => ProposalVersionLifecycle::Draft],
        ['stage' => 'sent', 'outcome' => 'hold', 'lifecycle' => ProposalVersionLifecycle::Sent],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $proposals = DB::table('proposals')->whereNull('current_version_id')->orderBy('id')->get();

        if ($proposals->isEmpty()) {
            $this->info('No Proposal needs a legacy V1 backfill — every Proposal already has current_version_id set.');

            return self::SUCCESS;
        }

        $planned = [];
        $anomalies = [];

        foreach ($proposals as $proposal) {
            $combination = $this->matchCombination($proposal);

            if ($combination === null) {
                $anomalies[] = $proposal;

                continue;
            }

            $planned[] = [$proposal, $combination];
        }

        if ($anomalies !== []) {
            $this->error('Refusing to run: found Proposal row(s) whose stage/outcome combination is not one of the confirmed production combinations. No row was changed.');

            foreach ($anomalies as $proposal) {
                $this->error(" - Proposal #{$proposal->id}: stage=".($proposal->stage ?? 'NULL').', outcome='.($proposal->outcome ?? 'NULL'));
            }

            $this->error('Resolve the mapping for these Proposal(s) explicitly (extend KNOWN_COMBINATIONS only after business sign-off) before re-running.');

            return self::FAILURE;
        }

        $rows = [];

        foreach ($planned as [$proposal, $combination]) {
            $rows[] = [
                $proposal->id,
                $proposal->stage,
                $proposal->outcome ?? 'NULL',
                $proposal->value ?? 'NULL',
                $proposal->sent_at ?? 'NULL',
                $combination['lifecycle']->value,
            ];
        }

        $this->table(
            ['Proposal ID', 'Legacy Stage', 'Legacy Outcome', 'Legacy Value', 'Legacy Sent At', '-> V1 Lifecycle'],
            $rows
        );

        if ($dryRun) {
            $this->info(count($planned).' Proposal(s) would receive a legacy V1 ProposalVersion. Dry run — no rows were written. Re-run without --dry-run to apply.');

            return self::SUCCESS;
        }

        foreach ($planned as [$proposal, $combination]) {
            DB::transaction(function () use ($proposal, $combination) {
                $this->backfillOne($proposal, $combination);
            });
        }

        $this->info(count($planned).' Proposal(s) backfilled with a legacy V1 ProposalVersion.');

        $this->verify();

        return self::SUCCESS;
    }

    /**
     * @return array{stage: string, outcome: ?string, lifecycle: ProposalVersionLifecycle}|null
     */
    private function matchCombination(object $proposal): ?array
    {
        foreach (self::KNOWN_COMBINATIONS as $combination) {
            if ($proposal->stage === $combination['stage'] && $proposal->outcome === $combination['outcome']) {
                return $combination;
            }
        }

        return null;
    }

    /**
     * @param  array{stage: string, outcome: ?string, lifecycle: ProposalVersionLifecycle}  $combination
     */
    private function backfillOne(object $proposal, array $combination): void
    {
        $now = now();

        $versionId = DB::table('proposal_versions')->insertGetId([
            'organization_id' => $proposal->organization_id,
            'proposal_id' => $proposal->id,
            'version_number' => 1,
            'lifecycle_status' => $combination['lifecycle']->value,
            'is_legacy_backfill' => true,
            'grand_total' => $proposal->value,
            'sent_at' => $proposal->sent_at,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('proposals')->where('id', $proposal->id)->update([
            'current_version_id' => $versionId,
            'updated_at' => $now,
        ]);
    }

    private function verify(): void
    {
        $remaining = DB::table('proposals')->whereNull('current_version_id')->count();

        if ($remaining > 0) {
            $this->error("Backfill verification failed: {$remaining} Proposal(s) still have a NULL current_version_id.");

            return;
        }

        $this->info('Verification passed: every Proposal now has current_version_id set.');
    }
}
