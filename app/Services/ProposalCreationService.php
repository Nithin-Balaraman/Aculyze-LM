<?php

namespace App\Services;

use App\Enums\ProposalVersionLifecycle;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\Prospect;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Phase 4A-2.4: the ONLY application-level owner of new runtime Proposal
 * creation. Every runtime path that used to create a bare Proposal (with no
 * ProposalVersion at all — the real gap this sub-stage closes, locked
 * Decision 12) now delegates here instead, so a Proposal can never again be
 * created without simultaneously receiving its V1 Draft and
 * current_version_id in the same atomic operation.
 *
 * V1 is Draft, always — no commercial submission/approval/sending evidence
 * exists at creation time, regardless of what legacy stage/outcome the
 * caller's OWN Proposal-parent semantics assign in the same call (the
 * temporary PHASE4_OUTCOME_CUTOVER_GATE coexistence — see
 * App\Services\ProposalVersionWorkflowService's own docblock for the
 * matching principle on the Version side). This service never reads or
 * writes Proposal.outcome/stage/winning_version_id beyond passing through
 * exactly what the caller supplied for its own Proposal-parent fields.
 *
 * V1's commercial snapshot is pulled ONLY from Prospect master data at this
 * exact moment — never from a prior ProposalVersion (that is revision
 * cloning, owned by ProposalVersionWorkflowService::cloneIntoNewDraft(),
 * a completely separate concern this service does not touch).
 *
 * Duplicate/retry semantics: firstOrCreate-shaped — an existing Proposal
 * for the given Lead is returned untouched (no second Proposal, no V1
 * created/repaired for it) rather than throwing a raw unique-constraint
 * violation. This matches the ONE call site that already had this exact
 * semantic (WorkflowTransitionService::createProposalFromLead()) and is
 * deliberately extended to the other three call sites too: each of them
 * already has its OWN UI-level "this Lead already has a Proposal" gate
 * upstream (PipelineBoard::crossDropSupported(), ProposalResource's
 * lead_id Select excluding already-claimed Leads), so this is a graceful
 * backstop for a race/stale-request, never an expected/observed path in
 * ordinary use — see the Phase 4A-2.4 completion report for the full
 * reasoning.
 */
class ProposalCreationService
{
    /**
     * Creates a Proposal + its V1 Draft ProposalVersion atomically, or
     * returns the existing Proposal untouched if one already exists for
     * this Lead. Owns its own transaction — safe to call from inside an
     * already-active outer transaction (Laravel's nested-transaction
     * savepoints), but never depends on one being present.
     *
     * @param  array<string, mixed>  $proposalAttributes  Every Proposal field the caller legitimately
     *                                                    supplies today (assigned_to/created_by/stage/outcome/value/sent_at/notes/attachment_paths/
     *                                                    attachment_names) — passed straight through to Proposal::create(), preserving each caller's
     *                                                    own existing parent-Proposal semantics exactly. lead_id/prospect_id are never accepted here —
     *                                                    both are always derived from $lead itself, so they can never disagree with it.
     */
    public function createForLead(Lead $lead, array $proposalAttributes): Proposal
    {
        return DB::transaction(function () use ($lead, $proposalAttributes) {
            $lockedLead = Lead::query()->whereKey($lead->getKey())->lockForUpdate()->firstOrFail();

            // A raw, scope-independent read (the same idiom every
            // inheritedOrganizationId() override in this codebase already
            // uses) rather than an Eloquent Prospect query — Eloquent's own
            // OrganizationScope would otherwise silently filter out a
            // genuinely cross-organization Prospect BEFORE this check could
            // ever see it and explain why, converging every such case into
            // an indistinguishable "not found" instead. Bypassing
            // OrganizationScope on an Eloquent query is deliberately
            // restricted to App\Support\Tenancy\Tenancy (see
            // tests/Feature/TenancyBypassUsageTest.php) — this reads the
            // table directly instead, never touching that scope at all.
            $prospectRow = DB::table('prospects')->where('id', $lockedLead->prospect_id)->first();

            if ($prospectRow === null) {
                throw new LogicException(
                    "Lead #{$lockedLead->getKey()} has no resolvable Prospect — cannot create a Proposal."
                );
            }

            if ((int) $prospectRow->organization_id !== $lockedLead->organization_id) {
                throw new LogicException(
                    "Lead #{$lockedLead->getKey()}'s Prospect #{$prospectRow->id} belongs to a different ".
                    'organization — refusing to create a Proposal.'
                );
            }

            $prospect = Prospect::query()
                ->withoutGlobalScope(SoftDeletingScope::class)
                ->whereKey($prospectRow->id)
                ->firstOrFail();

            $existing = Proposal::query()->where('lead_id', $lockedLead->getKey())->first();

            if ($existing !== null) {
                return $existing;
            }

            $proposal = Proposal::create(array_merge($proposalAttributes, [
                'lead_id' => $lockedLead->getKey(),
                'prospect_id' => $prospect->getKey(),
            ]));

            $version = ProposalVersion::create([
                'proposal_id' => $proposal->getKey(),
                'version_number' => 1,
                'lifecycle_status' => ProposalVersionLifecycle::Draft,
                'is_legacy_backfill' => false,
                'customer_name_snapshot' => $prospect->company_name,
                'customer_gstin_snapshot' => $prospect->gstin,
                'billing_address_snapshot' => $prospect->billing_address,
                'billing_state_snapshot' => $prospect->billing_state,
            ]);

            $proposal->forceFill(['current_version_id' => $version->getKey()])->save();

            return $proposal;
        });
    }
}
