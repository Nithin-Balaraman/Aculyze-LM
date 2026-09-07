<?php

namespace App\Services;

use App\Enums\ProposalVersionLifecycle;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use App\Models\ProposalVersionLineTaxComponent;
use App\Models\User;
use App\Policies\ProposalVersionPolicy;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Phase 4A-2.2: the centralized, transactional ProposalVersion commercial
 * workflow — Draft -> Submitted -> Approved, or Submitted -> Returned (+ a
 * new Draft), or Approved/Sent -> a new Draft ("Create Revision"). Mirrors
 * WorkflowTransitionService/RescheduleService's own established pattern
 * (lock -> re-verify current state -> mutate/clone -> AuditLogger -> return),
 * with one deliberate addition: every method here also enforces
 * authorization itself (via ProposalVersionPolicy), since "Do not rely on
 * future button visibility" is a locked 4A-2.2 requirement — unlike
 * WorkflowTransitionService, which leaves authorization entirely to its
 * Filament callers.
 *
 * Every method operates through ordinary Eloquent queries under the
 * ambient OrganizationScope — never App\Support\Tenancy\Tenancy's bypass
 * mechanism (see tests/Feature/TenancyBypassUsageTest.php) — the same
 * precedent already established by WorkflowTransitionService/
 * RescheduleService.
 *
 * PHASE4_OUTCOME_CUTOVER_GATE remains OPEN: nothing here ever writes
 * Proposal.outcome, Proposal.stage, or Proposal.winning_version_id — the
 * ProposalVersion commercial lifecycle and the Proposal parent's own
 * outcome are deliberately separate concerns (locked Decisions 14/17/18).
 */
class ProposalVersionWorkflowService
{
    /**
     * Cloned verbatim from the source Version into every new Draft this
     * service creates (Return for Revision, Create Revision) — customer/
     * commercial snapshot content only, never workflow evidence, and never
     * refreshed from Prospect (locked Decisions 5/17).
     *
     * @var array<int, string>
     */
    private const CLONED_SNAPSHOT_FIELDS = [
        'customer_name_snapshot',
        'customer_gstin_snapshot',
        'billing_address_snapshot',
        'billing_state_snapshot',
        'place_of_supply_snapshot',
        'payment_terms',
        'validity_terms',
        'scope_notes',
        'subtotal',
        'total_discount',
        'tax_total',
        'grand_total',
        'currency_code',
    ];

    /**
     * Manager-or-above only. Draft -> Submitted. Runs structural validation
     * first, then the authoritative Brick\Math financial recalculation
     * (4A-2.3's ProposalVersionCalculator) before freezing the transition.
     */
    public function submit(ProposalVersion $version, User $actor): void
    {
        DB::transaction(function () use ($version, $actor) {
            [$proposal, $locked] = $this->lockProposalAndVersion($version);

            if ($locked->lifecycle_status !== ProposalVersionLifecycle::Draft) {
                throw new LogicException(
                    "ProposalVersion #{$locked->getKey()} cannot be submitted: its lifecycle is {$locked->lifecycle_status->value}, not draft."
                );
            }

            if (! app(ProposalVersionPolicy::class)->submit($actor, $locked)) {
                throw new LogicException("You are not authorized to submit ProposalVersion #{$locked->getKey()} for final approval.");
            }

            $this->assertStructuralSubmitPrerequisites($locked);

            // 4A-2.3: authoritative Brick\Math recalculation — stages (but
            // does not itself save) $locked's subtotal/total_discount/
            // tax_total/grand_total, and separately saves each line/tax
            // component's own recalculated amounts. A LogicException here
            // (invalid discount/tax data) rolls back this entire
            // transaction, including any line/component already saved by
            // this call — Draft is left completely unchanged.
            app(ProposalVersionCalculator::class)->recalculate($locked);

            $locked->forceFill([
                'lifecycle_status' => ProposalVersionLifecycle::Submitted,
                'submitted_by' => $actor->getKey(),
                'submitted_at' => now(),
            ])->save();

            AuditLogger::record(
                entityType: 'ProposalVersion',
                entityId: $locked->getKey(),
                action: 'proposal_version_submitted',
                organizationId: $locked->organization_id,
                before: ['lifecycle_status' => ProposalVersionLifecycle::Draft->value],
                after: [
                    'lifecycle_status' => ProposalVersionLifecycle::Submitted->value,
                    'proposal_id' => $proposal->getKey(),
                ],
            );
        });
    }

    /** Senior Manager only, and never the submitter (locked Decision 19). Submitted -> Approved. */
    public function approve(ProposalVersion $version, User $actor, ?string $comment = null): void
    {
        DB::transaction(function () use ($version, $actor, $comment) {
            [, $locked] = $this->lockProposalAndVersion($version);

            if ($locked->lifecycle_status !== ProposalVersionLifecycle::Submitted) {
                throw new LogicException(
                    "ProposalVersion #{$locked->getKey()} cannot be approved: its lifecycle is {$locked->lifecycle_status->value}, not submitted."
                );
            }

            if (! app(ProposalVersionPolicy::class)->approve($actor, $locked)) {
                throw new LogicException(
                    "You are not authorized to approve ProposalVersion #{$locked->getKey()} — either you are not a Senior Manager, or you are the same actor who submitted it (self-approval is never allowed)."
                );
            }

            $locked->forceFill([
                'lifecycle_status' => ProposalVersionLifecycle::Approved,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'approval_comment' => $comment,
            ])->save();

            AuditLogger::record(
                entityType: 'ProposalVersion',
                entityId: $locked->getKey(),
                action: 'proposal_version_approved',
                organizationId: $locked->organization_id,
                before: ['lifecycle_status' => ProposalVersionLifecycle::Submitted->value],
                after: ['lifecycle_status' => ProposalVersionLifecycle::Approved->value, 'comment' => $comment],
            );
        });
    }

    /**
     * Senior Manager only. Submitted -> ReturnedForRevision, atomically
     * creating a new Draft (the old row is never reopened — it becomes
     * permanent, frozen history).
     */
    public function returnForRevision(ProposalVersion $version, User $actor, string $reason): ProposalVersion
    {
        if (blank($reason)) {
            throw new LogicException('A return reason is required to return a ProposalVersion for revision.');
        }

        return DB::transaction(function () use ($version, $actor, $reason) {
            [$proposal, $locked] = $this->lockProposalAndVersion($version);

            if ($locked->lifecycle_status !== ProposalVersionLifecycle::Submitted) {
                throw new LogicException(
                    "ProposalVersion #{$locked->getKey()} cannot be returned for revision: its lifecycle is {$locked->lifecycle_status->value}, not submitted."
                );
            }

            if (! app(ProposalVersionPolicy::class)->returnForRevision($actor, $locked)) {
                throw new LogicException("You are not authorized to return ProposalVersion #{$locked->getKey()} for revision.");
            }

            $this->assertNoConflictingActiveDraft($proposal);

            $newDraft = $this->cloneIntoNewDraft($proposal, $locked);

            $locked->forceFill([
                'lifecycle_status' => ProposalVersionLifecycle::ReturnedForRevision,
                'returned_by' => $actor->getKey(),
                'returned_at' => now(),
                'return_reason' => $reason,
                'superseded_at' => now(),
                'superseded_by_version_id' => $newDraft->getKey(),
            ])->save();

            $proposal->forceFill(['current_version_id' => $newDraft->getKey()])->save();

            AuditLogger::record(
                entityType: 'ProposalVersion',
                entityId: $locked->getKey(),
                action: 'proposal_version_returned_for_revision',
                organizationId: $locked->organization_id,
                before: ['lifecycle_status' => ProposalVersionLifecycle::Submitted->value],
                after: [
                    'lifecycle_status' => ProposalVersionLifecycle::ReturnedForRevision->value,
                    'reason' => $reason,
                    'new_version_id' => $newDraft->getKey(),
                ],
            );

            AuditLogger::record(
                entityType: 'ProposalVersion',
                entityId: $newDraft->getKey(),
                action: 'proposal_version_revision_created',
                organizationId: $newDraft->organization_id,
                before: null,
                after: [
                    'source_version_id' => $locked->getKey(),
                    'trigger' => 'returned_for_revision',
                    'version_number' => $newDraft->version_number,
                ],
            );

            return $newDraft;
        });
    }

    /**
     * Manager-or-above (Employee excluded). Approved or Sent (including
     * legacy backfilled Sent versions, with no special-casing) -> a new
     * Draft. The old Version's lifecycle_status is NEVER rewritten — it
     * stays exactly Approved or Sent forever, only gaining supersession
     * metadata.
     */
    public function createRevision(ProposalVersion $version, User $actor): ProposalVersion
    {
        return DB::transaction(function () use ($version, $actor) {
            [$proposal, $locked] = $this->lockProposalAndVersion($version);

            if (! in_array($locked->lifecycle_status, [ProposalVersionLifecycle::Approved, ProposalVersionLifecycle::Sent], true)) {
                throw new LogicException(
                    "ProposalVersion #{$locked->getKey()} cannot start a new revision: its lifecycle is {$locked->lifecycle_status->value}, not approved or sent."
                );
            }

            if (! app(ProposalVersionPolicy::class)->createRevision($actor, $locked)) {
                throw new LogicException("You are not authorized to create a revision of ProposalVersion #{$locked->getKey()}.");
            }

            $this->assertNoConflictingActiveDraft($proposal);

            $newDraft = $this->cloneIntoNewDraft($proposal, $locked);

            $locked->forceFill([
                'superseded_at' => now(),
                'superseded_by_version_id' => $newDraft->getKey(),
            ])->save();

            $proposal->forceFill(['current_version_id' => $newDraft->getKey()])->save();

            AuditLogger::record(
                entityType: 'ProposalVersion',
                entityId: $newDraft->getKey(),
                action: 'proposal_version_revision_created',
                organizationId: $newDraft->organization_id,
                before: null,
                after: [
                    'source_version_id' => $locked->getKey(),
                    'trigger' => 'manual_revision',
                    'version_number' => $newDraft->version_number,
                ],
            );

            return $newDraft;
        });
    }

    /**
     * Locks the Proposal row FIRST (serializing every Version-creation
     * transition for the same Proposal — the exact locked concurrency
     * principle), then re-locks/re-reads the ProposalVersion by its own
     * key — never trusting any field on the caller-supplied $version
     * instance except its immutable identity (proposal_id/organization_id,
     * which App\Models\ProposalVersion's own 4A-2.1 guard makes permanently
     * safe to read even from a stale instance). Every other field is read
     * only from the freshly-locked row returned here.
     *
     * @return array{0: Proposal, 1: ProposalVersion}
     */
    private function lockProposalAndVersion(ProposalVersion $version): array
    {
        $proposal = Proposal::query()->whereKey($version->proposal_id)->lockForUpdate()->firstOrFail();
        $locked = ProposalVersion::query()->whereKey($version->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->proposal_id !== $proposal->getKey() || $locked->organization_id !== $proposal->organization_id) {
            throw new LogicException(
                "ProposalVersion #{$locked->getKey()} does not belong to Proposal #{$proposal->getKey()}."
            );
        }

        if ($proposal->current_version_id !== $locked->getKey()) {
            throw new LogicException(
                "ProposalVersion #{$locked->getKey()} is no longer Proposal #{$proposal->getKey()}'s current Version — reload the latest state before retrying."
            );
        }

        // Avoids a second, redundant query when the Policy (or a future
        // caller) accesses $locked->proposal — we already hold the exact
        // same row locked.
        $locked->setRelation('proposal', $proposal);

        return [$proposal, $locked];
    }

    private function assertNoConflictingActiveDraft(Proposal $proposal): void
    {
        $existingDraft = DB::table('proposal_versions')
            ->where('proposal_id', $proposal->getKey())
            ->where('lifecycle_status', ProposalVersionLifecycle::Draft->value)
            ->exists();

        if ($existingDraft) {
            throw new LogicException(
                "Proposal #{$proposal->getKey()} already has an active Draft Version — resolve it before creating another."
            );
        }
    }

    /**
     * Structural Submit validation ONLY — no financial recalculation.
     * 4A-2.3's ProposalVersionCalculator owns authoritative discount/tax
     * arithmetic and the final recalculated totals; nothing here computes
     * or trusts a derived amount, only checks presence/sign of already-
     * persisted values using plain numeric comparison (never treated as
     * financial authority).
     */
    private function assertStructuralSubmitPrerequisites(ProposalVersion $version): void
    {
        if (blank($version->customer_name_snapshot)) {
            throw new LogicException("ProposalVersion #{$version->getKey()} cannot be submitted: customer name is required.");
        }

        if (blank($version->payment_terms)) {
            throw new LogicException("ProposalVersion #{$version->getKey()} cannot be submitted: payment terms are required.");
        }

        if (blank($version->validity_terms)) {
            throw new LogicException("ProposalVersion #{$version->getKey()} cannot be submitted: validity terms are required.");
        }

        if (blank($version->currency_code)) {
            throw new LogicException("ProposalVersion #{$version->getKey()} cannot be submitted: a currency code is required.");
        }

        $lines = $version->lines()->with('taxComponents')->get();

        if ($lines->isEmpty()) {
            throw new LogicException("ProposalVersion #{$version->getKey()} cannot be submitted: at least one line item is required.");
        }

        $hasAnyTaxComponent = false;

        foreach ($lines as $line) {
            if (blank($line->item_name)) {
                throw new LogicException("ProposalVersion #{$version->getKey()} cannot be submitted: line #{$line->line_number} has no item name.");
            }

            if (! ((float) $line->quantity > 0)) {
                throw new LogicException("ProposalVersion #{$version->getKey()} cannot be submitted: line #{$line->line_number}'s quantity must be greater than zero.");
            }

            if ((float) $line->unit_price < 0) {
                throw new LogicException("ProposalVersion #{$version->getKey()} cannot be submitted: line #{$line->line_number}'s unit price must not be negative.");
            }

            foreach ($line->taxComponents as $component) {
                $hasAnyTaxComponent = true;

                if ($component->component_type === null) {
                    throw new LogicException("ProposalVersion #{$version->getKey()} cannot be submitted: line #{$line->line_number} has a tax component with no component type.");
                }

                if ((float) $component->rate < 0) {
                    throw new LogicException("ProposalVersion #{$version->getKey()} cannot be submitted: line #{$line->line_number} has a tax component with a negative rate.");
                }
            }
        }

        if ($hasAnyTaxComponent) {
            if (blank($version->billing_state_snapshot)) {
                throw new LogicException("ProposalVersion #{$version->getKey()} cannot be submitted: a billing state is required whenever a tax component is present.");
            }

            if (blank($version->place_of_supply_snapshot)) {
                throw new LogicException("ProposalVersion #{$version->getKey()} cannot be submitted: a place of supply is required whenever a tax component is present.");
            }
        }

        // Deliberately NOT validated here: authoritative discount-
        // percentage-range/fixed-discount-vs-gross checks and any
        // recalculation of gross/discount/taxable/tax/line totals — that is
        // ProposalVersionCalculator::recalculate()'s job (4A-2.3), called
        // separately by submit() right after this method returns.
    }

    /**
     * Allocates the next version_number for $proposal WHILE its row lock
     * (already held by the caller) is in effect — the parent lock is what
     * actually serializes this, the FOR UPDATE on the MAX query itself is
     * defense-in-depth, matching this codebase's existing
     * UNIQUE-constraint-as-backstop philosophy. Clones the source Version's
     * commercial snapshot, lines, and each line's tax components into
     * entirely new, independent rows — never workflow evidence, never
     * re-read from Prospect.
     */
    private function cloneIntoNewDraft(Proposal $proposal, ProposalVersion $source): ProposalVersion
    {
        $nextVersionNumber = (int) DB::table('proposal_versions')
            ->where('proposal_id', $proposal->getKey())
            ->lockForUpdate()
            ->max('version_number') + 1;

        $snapshot = collect(self::CLONED_SNAPSHOT_FIELDS)
            ->mapWithKeys(fn (string $field) => [$field => $source->{$field}])
            ->all();

        $newDraft = ProposalVersion::create(array_merge($snapshot, [
            'proposal_id' => $proposal->getKey(),
            'version_number' => $nextVersionNumber,
            'lifecycle_status' => ProposalVersionLifecycle::Draft,
            'is_legacy_backfill' => false,
        ]));

        foreach ($source->lines()->with('taxComponents')->get() as $sourceLine) {
            $newLine = ProposalVersionLine::create([
                'proposal_version_id' => $newDraft->getKey(),
                'line_number' => $sourceLine->line_number,
                'item_name' => $sourceLine->item_name,
                'description' => $sourceLine->description,
                'hsn_sac' => $sourceLine->hsn_sac,
                'quantity' => $sourceLine->quantity,
                'unit' => $sourceLine->unit,
                'unit_price' => $sourceLine->unit_price,
                'discount_type' => $sourceLine->discount_type,
                'discount_value' => $sourceLine->discount_value,
                'discount_amount' => $sourceLine->discount_amount,
                'gross_amount' => $sourceLine->gross_amount,
                'taxable_amount' => $sourceLine->taxable_amount,
                'tax_amount' => $sourceLine->tax_amount,
                'line_total' => $sourceLine->line_total,
            ]);

            foreach ($sourceLine->taxComponents as $sourceComponent) {
                ProposalVersionLineTaxComponent::create([
                    'proposal_version_line_id' => $newLine->getKey(),
                    'component_type' => $sourceComponent->component_type,
                    'rate' => $sourceComponent->rate,
                    'amount' => $sourceComponent->amount,
                ]);
            }
        }

        return $newDraft;
    }
}
