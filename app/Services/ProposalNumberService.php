<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Proposal;
use App\Models\ProposalNumberSequence;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4A-3.1 (locked Decision 1): allocates a Proposal's permanent,
 * human-readable number — format `ACU-2026-0001` — on first Version
 * approval. The number belongs to the parent Proposal forever; every later
 * revision/approval shares it, and it is never reassigned once set.
 *
 * Intended to be called from WITHIN an already-open transaction (
 * ProposalVersionWorkflowService::approve()'s own Proposal+Version lock) —
 * this service never opens its own transaction, only participates in the
 * caller's.
 *
 * Concurrency: allocation is serialized per (organization_id, year) via a
 * dedicated counter row in proposal_number_sequences, never MAX()+1.
 * Locking the Proposal/Version rows (already done by the caller) does NOT
 * by itself serialize numbering across DIFFERENT Proposals in the same
 * organization — this counter row is what actually does. The first-row
 * race (two Proposals in the same organization/year approved concurrently,
 * neither counter row existing yet) is handled with insert-or-ignore
 * followed by SELECT ... FOR UPDATE: both transactions attempt to create
 * the counter row (`INSERT IGNORE`, so at most one actually inserts and the
 * other is silently skipped, never erroring), then both lock and read the
 * now-guaranteed-existing row in turn — the second transaction blocks on
 * the row lock until the first commits its increment, exactly serializing
 * the allocation. `proposals(organization_id, proposal_number)`'s existing
 * UNIQUE constraint remains the final backstop.
 */
class ProposalNumberService
{
    private const DEFAULT_PREFIX = 'ACU';

    /**
     * No-op if `$proposal->proposal_number` is already set — allocation
     * happens exactly once, on whichever approval is the Proposal's first.
     */
    public function allocateIfMissing(Proposal $proposal): void
    {
        if ($proposal->proposal_number !== null) {
            return;
        }

        $organizationId = $proposal->organization_id;
        $year = (int) now()->year;

        DB::table('proposal_number_sequences')->insertOrIgnore([
            'organization_id' => $organizationId,
            'year' => $year,
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = ProposalNumberSequence::query()
            ->where('organization_id', $organizationId)
            ->where('year', $year)
            ->lockForUpdate()
            ->firstOrFail();

        $number = $sequence->next_number;

        $sequence->forceFill(['next_number' => $number + 1])->save();

        $proposal->forceFill([
            'proposal_number' => sprintf('%s-%d-%04d', $this->prefixFor($organizationId), $year, $number),
        ])->save();
    }

    /** Organization-configurable via organizations.settings, default ACU — no identity/settings UI in 4A-3, so this key is set (if at all) directly in the database for now. */
    private function prefixFor(int $organizationId): string
    {
        $settings = Organization::query()->find($organizationId)?->settings ?? [];

        return (string) ($settings['proposal_number_prefix'] ?? self::DEFAULT_PREFIX);
    }
}
