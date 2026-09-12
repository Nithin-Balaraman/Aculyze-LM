<?php

namespace App\Services;

use App\Enums\ContactMode;
use App\Enums\ProposalClientResponseNextAction;
use App\Enums\ProposalClientResponseType;
use App\Enums\ProposalOutcome;
use App\Enums\ProposalStage;
use App\Enums\ProposalVersionLifecycle;
use App\Models\FollowUp;
use App\Models\Proposal;
use App\Models\ProposalClientResponse;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use App\Models\ProposalVersionLineTaxComponent;
use App\Models\User;
use App\Policies\ProposalClientResponsePolicy;
use App\Support\Audit\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Phase 4A-3.4: the complete internal client-response workflow for a Sent
 * ProposalVersion (PHASE4_OUTCOME_CUTOVER_GATE item 1). Every response is
 * an append-only fact recorded against the EXACT Sent Version the customer
 * responded to — which does NOT have to equal the Proposal's current
 * Version (locked Decision 11) — followed by response-specific controlled
 * behavior (Won/Hold/Lost transitions, Draft creation/reuse, Follow-Up
 * creation, billing handoff). No customer-facing portal, no e-signature, no
 * external billing call exists anywhere in this class.
 *
 * Recording the customer FACT is authorized separately from generic
 * commercial-edit authority: `ProposalClientResponsePolicy::
 * recordClientResponse()` allows the assigned Employee, a Manager within
 * hierarchy, or a Senior Manager organization-wide to record ANY of the
 * five response types — this service never calls
 * ProposalVersionPolicy::createRevision() (Manager-or-above only) even
 * when its own internal clone mechanics mirror createRevision()'s shape,
 * so an Employee-recorded Revision Requested is never wrongly rejected.
 */
class ProposalClientResponseService
{
    /**
     * Cloned verbatim into a new Draft ONLY from a non-legacy source — for
     * a legacy backfilled Sent Version, the totals fields are excluded
     * (see cloneSentVersionIntoDraft()) since a legacy row's grand_total is
     * unverified historical data, never authoritative commercial content
     * for a fresh Draft (Section J).
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
        'currency_code',
    ];

    private const CLONED_TOTAL_FIELDS = [
        'subtotal',
        'total_discount',
        'tax_total',
        'grand_total',
    ];

    // --- Accepted -------------------------------------------------------

    public function recordAccepted(ProposalVersion $version, User $actor, ?string $notes, string $idempotencyKey): ProposalClientResponse
    {
        $existing = ProposalClientResponse::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            $this->assertSamePayload($existing, $version, ProposalClientResponseType::Accepted, null, $notes, null, null);

            return $existing;
        }

        return DB::transaction(function () use ($version, $actor, $notes, $idempotencyKey) {
            [$proposal, $responseVersion] = $this->lockProposalAndValidateResponseVersion($version, $actor);

            $raceExisting = ProposalClientResponse::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($raceExisting !== null) {
                $this->assertSamePayload($raceExisting, $responseVersion, ProposalClientResponseType::Accepted, null, $notes, null, null);

                return $raceExisting;
            }

            $response = ProposalClientResponse::create([
                'proposal_id' => $proposal->getKey(),
                'proposal_version_id' => $responseVersion->getKey(),
                'response_type' => ProposalClientResponseType::Accepted,
                'notes' => $notes,
                'recorded_by' => $actor->getKey(),
                'recorded_at' => now(),
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->ensureProposalHasMeaningfulNotesForOutcome(
                $proposal,
                "Won — client accepted Proposal Version V{$responseVersion->version_number} (client response #{$response->getKey()} recorded ".now()->toDateString().').'
            );

            $proposal->forceFill([
                'outcome' => ProposalOutcome::Won,
                'stage' => ProposalStage::CustomerAccepted,
                'winning_version_id' => $responseVersion->getKey(),
                'value' => $responseVersion->grand_total,
            ])->save();

            $handoff = app(ProposalBillingHandoffService::class)->createPendingHandoff($proposal, $responseVersion, $response);

            AuditLogger::record(
                entityType: 'ProposalClientResponse',
                entityId: $response->getKey(),
                action: 'proposal_client_response_accepted',
                organizationId: $proposal->organization_id,
                after: [
                    'proposal_id' => $proposal->getKey(),
                    'proposal_version_id' => $responseVersion->getKey(),
                    'winning_version_id' => $responseVersion->getKey(),
                    'value' => $responseVersion->grand_total,
                    'billing_handoff_id' => $handoff->getKey(),
                ],
            );

            return $response;
        });
    }

    // --- Revision Requested ----------------------------------------------

    public function recordRevisionRequested(ProposalVersion $version, User $actor, ?string $reason, ?string $notes, string $idempotencyKey): ProposalClientResponse
    {
        $existing = ProposalClientResponse::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            $this->assertSamePayload($existing, $version, ProposalClientResponseType::RevisionRequested, $reason, $notes, null, null);

            return $existing;
        }

        return DB::transaction(function () use ($version, $actor, $reason, $notes, $idempotencyKey) {
            [$proposal, $responseVersion] = $this->lockProposalAndValidateResponseVersion($version, $actor);

            $raceExisting = ProposalClientResponse::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($raceExisting !== null) {
                $this->assertSamePayload($raceExisting, $responseVersion, ProposalClientResponseType::RevisionRequested, $reason, $notes, null, null);

                return $raceExisting;
            }

            $response = ProposalClientResponse::create([
                'proposal_id' => $proposal->getKey(),
                'proposal_version_id' => $responseVersion->getKey(),
                'response_type' => ProposalClientResponseType::RevisionRequested,
                'reason' => $reason,
                'notes' => $notes,
                'recorded_by' => $actor->getKey(),
                'recorded_at' => now(),
                'idempotency_key' => $idempotencyKey,
            ]);

            $activeDraft = ProposalVersion::query()
                ->where('proposal_id', $proposal->getKey())
                ->where('lifecycle_status', ProposalVersionLifecycle::Draft)
                ->lockForUpdate()
                ->first();

            if ($activeDraft === null) {
                $draft = $this->cloneSentVersionIntoDraft($proposal, $responseVersion);
                $proposal->forceFill(['current_version_id' => $draft->getKey()]);
            } else {
                $draft = $activeDraft;
            }

            $response->forceFill(['resulting_draft_version_id' => $draft->getKey()])->save();

            if ($proposal->outcome === ProposalOutcome::Hold) {
                $proposal->outcome = null;
            }

            $proposal->forceFill(['stage' => ProposalStage::BeingPrepared])->save();

            AuditLogger::record(
                entityType: 'ProposalClientResponse',
                entityId: $response->getKey(),
                action: 'proposal_client_response_revision_requested',
                organizationId: $proposal->organization_id,
                after: [
                    'proposal_id' => $proposal->getKey(),
                    'proposal_version_id' => $responseVersion->getKey(),
                    'resulting_draft_version_id' => $draft->getKey(),
                    'draft_was_reused' => $activeDraft !== null,
                ],
            );

            return $response;
        });
    }

    // --- More Time / Decision Pending -------------------------------------

    public function recordMoreTime(
        ProposalVersion $version,
        User $actor,
        CarbonInterface $followUpAt,
        string $reason,
        ?string $notes,
        ?string $followUpNotes,
        ?ContactMode $contactMode,
        string $idempotencyKey,
    ): ProposalClientResponse {
        if (blank($reason)) {
            throw new LogicException('A reason is required to record More Time / Decision Pending.');
        }

        $existing = ProposalClientResponse::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            $this->assertSamePayload($existing, $version, ProposalClientResponseType::MoreTime, $reason, $notes, null, $followUpAt);

            return $existing;
        }

        return DB::transaction(function () use ($version, $actor, $followUpAt, $reason, $notes, $followUpNotes, $contactMode, $idempotencyKey) {
            [$proposal, $responseVersion] = $this->lockProposalAndValidateResponseVersion($version, $actor);

            $raceExisting = ProposalClientResponse::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($raceExisting !== null) {
                $this->assertSamePayload($raceExisting, $responseVersion, ProposalClientResponseType::MoreTime, $reason, $notes, null, $followUpAt);

                return $raceExisting;
            }

            $response = ProposalClientResponse::create([
                'proposal_id' => $proposal->getKey(),
                'proposal_version_id' => $responseVersion->getKey(),
                'response_type' => ProposalClientResponseType::MoreTime,
                'reason' => $reason,
                'notes' => $notes,
                'recorded_by' => $actor->getKey(),
                'recorded_at' => now(),
                'idempotency_key' => $idempotencyKey,
            ]);

            $followUp = $this->createFollowUpFromProposal($proposal, $followUpAt, $reason, $followUpNotes, $contactMode);

            $response->forceFill(['follow_up_id' => $followUp->getKey()])->save();

            // Deliberately does NOT write 'stage' at all — only outcome and
            // last_client_activity_at — so the model's own stage_changed_at
            // hook (which fires solely on isDirty('stage')) is never
            // touched (Section L, locked Decision 18).
            $proposal->forceFill([
                'outcome' => ProposalOutcome::Hold,
                'last_client_activity_at' => $response->recorded_at,
            ])->save();

            AuditLogger::record(
                entityType: 'ProposalClientResponse',
                entityId: $response->getKey(),
                action: 'proposal_client_response_more_time',
                organizationId: $proposal->organization_id,
                after: [
                    'proposal_id' => $proposal->getKey(),
                    'proposal_version_id' => $responseVersion->getKey(),
                    'follow_up_id' => $followUp->getKey(),
                    'follow_up_at' => $followUpAt->toIso8601String(),
                ],
            );

            return $response;
        });
    }

    // --- Rejected / No Further Progression --------------------------------

    public function recordRejected(ProposalVersion $version, User $actor, string $reason, ?string $notes, string $idempotencyKey): ProposalClientResponse
    {
        if (blank($reason)) {
            throw new LogicException('A reason is required to record Rejected / No Further Progression.');
        }

        $existing = ProposalClientResponse::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            $this->assertSamePayload($existing, $version, ProposalClientResponseType::Rejected, $reason, $notes, null, null);

            return $existing;
        }

        return DB::transaction(function () use ($version, $actor, $reason, $notes, $idempotencyKey) {
            [$proposal, $responseVersion] = $this->lockProposalAndValidateResponseVersion($version, $actor);

            $raceExisting = ProposalClientResponse::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($raceExisting !== null) {
                $this->assertSamePayload($raceExisting, $responseVersion, ProposalClientResponseType::Rejected, $reason, $notes, null, null);

                return $raceExisting;
            }

            $response = ProposalClientResponse::create([
                'proposal_id' => $proposal->getKey(),
                'proposal_version_id' => $responseVersion->getKey(),
                'response_type' => ProposalClientResponseType::Rejected,
                'reason' => $reason,
                'notes' => $notes,
                'recorded_by' => $actor->getKey(),
                'recorded_at' => now(),
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->ensureProposalHasMeaningfulNotesForOutcome($proposal, $reason);

            $proposal->forceFill([
                'outcome' => ProposalOutcome::Lost,
                'stage' => ProposalStage::CustomerRejected,
            ])->save();

            AuditLogger::record(
                entityType: 'ProposalClientResponse',
                entityId: $response->getKey(),
                action: 'proposal_client_response_rejected',
                organizationId: $proposal->organization_id,
                after: [
                    'proposal_id' => $proposal->getKey(),
                    'proposal_version_id' => $responseVersion->getKey(),
                    'reason' => $reason,
                ],
            );

            return $response;
        });
    }

    // --- Other: Await Further Contact -------------------------------------

    public function recordOtherAwaitFurtherContact(ProposalVersion $version, User $actor, string $notes, string $idempotencyKey): ProposalClientResponse
    {
        if (blank($notes)) {
            throw new LogicException('Notes are required to record Other — Await Further Contact.');
        }

        $existing = ProposalClientResponse::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            $this->assertSamePayload($existing, $version, ProposalClientResponseType::Other, null, $notes, ProposalClientResponseNextAction::AwaitFurtherContact, null);

            return $existing;
        }

        return DB::transaction(function () use ($version, $actor, $notes, $idempotencyKey) {
            [$proposal, $responseVersion] = $this->lockProposalAndValidateResponseVersion($version, $actor);

            $raceExisting = ProposalClientResponse::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($raceExisting !== null) {
                $this->assertSamePayload($raceExisting, $responseVersion, ProposalClientResponseType::Other, null, $notes, ProposalClientResponseNextAction::AwaitFurtherContact, null);

                return $raceExisting;
            }

            $response = ProposalClientResponse::create([
                'proposal_id' => $proposal->getKey(),
                'proposal_version_id' => $responseVersion->getKey(),
                'response_type' => ProposalClientResponseType::Other,
                'next_action' => ProposalClientResponseNextAction::AwaitFurtherContact,
                'notes' => $notes,
                'recorded_by' => $actor->getKey(),
                'recorded_at' => now(),
                'idempotency_key' => $idempotencyKey,
            ]);

            // Deliberately no outcome/stage/last_client_activity_at write at
            // all — Await Further Contact has no downstream record and no
            // stale-reference reset (Section N1).

            AuditLogger::record(
                entityType: 'ProposalClientResponse',
                entityId: $response->getKey(),
                action: 'proposal_client_response_other_await',
                organizationId: $proposal->organization_id,
                after: [
                    'proposal_id' => $proposal->getKey(),
                    'proposal_version_id' => $responseVersion->getKey(),
                ],
            );

            return $response;
        });
    }

    // --- Other: Create Follow-Up ------------------------------------------

    public function recordOtherCreateFollowUp(
        ProposalVersion $version,
        User $actor,
        CarbonInterface $followUpAt,
        string $reason,
        ?string $notes,
        ?string $followUpNotes,
        ?ContactMode $contactMode,
        string $idempotencyKey,
    ): ProposalClientResponse {
        if (blank($reason)) {
            throw new LogicException('A reason is required to record Other — Create Follow-Up.');
        }

        $existing = ProposalClientResponse::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            $this->assertSamePayload($existing, $version, ProposalClientResponseType::Other, $reason, $notes, ProposalClientResponseNextAction::CreateFollowUp, $followUpAt);

            return $existing;
        }

        return DB::transaction(function () use ($version, $actor, $followUpAt, $reason, $notes, $followUpNotes, $contactMode, $idempotencyKey) {
            [$proposal, $responseVersion] = $this->lockProposalAndValidateResponseVersion($version, $actor);

            $raceExisting = ProposalClientResponse::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($raceExisting !== null) {
                $this->assertSamePayload($raceExisting, $responseVersion, ProposalClientResponseType::Other, $reason, $notes, ProposalClientResponseNextAction::CreateFollowUp, $followUpAt);

                return $raceExisting;
            }

            $response = ProposalClientResponse::create([
                'proposal_id' => $proposal->getKey(),
                'proposal_version_id' => $responseVersion->getKey(),
                'response_type' => ProposalClientResponseType::Other,
                'next_action' => ProposalClientResponseNextAction::CreateFollowUp,
                'reason' => $reason,
                'notes' => $notes,
                'recorded_by' => $actor->getKey(),
                'recorded_at' => now(),
                'idempotency_key' => $idempotencyKey,
            ]);

            $followUp = $this->createFollowUpFromProposal($proposal, $followUpAt, $reason, $followUpNotes, $contactMode);

            $response->forceFill(['follow_up_id' => $followUp->getKey()])->save();

            $proposal->forceFill(['last_client_activity_at' => $response->recorded_at])->save();

            AuditLogger::record(
                entityType: 'ProposalClientResponse',
                entityId: $response->getKey(),
                action: 'proposal_client_response_other_create_follow_up',
                organizationId: $proposal->organization_id,
                after: [
                    'proposal_id' => $proposal->getKey(),
                    'proposal_version_id' => $responseVersion->getKey(),
                    'follow_up_id' => $followUp->getKey(),
                    'follow_up_at' => $followUpAt->toIso8601String(),
                ],
            );

            return $response;
        });
    }

    // --- Shared internals --------------------------------------------------

    /**
     * Locks the Proposal, locks/re-validates the EXACT named response
     * Version (belongs to this Proposal, lifecycle Sent — need NOT be the
     * current Version, locked Decision 11), authorizes recordClientResponse,
     * and refuses if the Proposal is already terminal (Won/Lost).
     *
     * @return array{0: Proposal, 1: ProposalVersion}
     */
    private function lockProposalAndValidateResponseVersion(ProposalVersion $version, User $actor): array
    {
        $proposal = Proposal::query()->whereKey($version->proposal_id)->lockForUpdate()->firstOrFail();
        $responseVersion = ProposalVersion::query()->whereKey($version->getKey())->lockForUpdate()->firstOrFail();

        if ($responseVersion->proposal_id !== $proposal->getKey() || $responseVersion->organization_id !== $proposal->organization_id) {
            throw new LogicException("ProposalVersion #{$responseVersion->getKey()} does not belong to Proposal #{$proposal->getKey()}.");
        }

        if ($responseVersion->lifecycle_status !== ProposalVersionLifecycle::Sent) {
            throw new LogicException(
                "ProposalVersion #{$responseVersion->getKey()} cannot receive a client response: its lifecycle is {$responseVersion->lifecycle_status->value}, not sent."
            );
        }

        if (! app(ProposalClientResponsePolicy::class)->recordClientResponse($actor, $proposal)) {
            throw new LogicException("You are not authorized to record a client response for Proposal #{$proposal->getKey()}.");
        }

        if (in_array($proposal->outcome, [ProposalOutcome::Won, ProposalOutcome::Lost], true)) {
            throw new LogicException(
                "Proposal #{$proposal->getKey()} cannot receive further client responses: its outcome is already {$proposal->outcome->getLabel()}."
            );
        }

        $responseVersion->setRelation('proposal', $proposal);

        return [$proposal, $responseVersion];
    }

    /**
     * Won/Lost require Proposal.notes to be meaningful (App\Models\
     * Proposal's own saving() guard) — never overwrites existing notes,
     * only fills a real, fact-based default when the Proposal genuinely has
     * none yet.
     */
    private function ensureProposalHasMeaningfulNotesForOutcome(Proposal $proposal, string $fallbackNote): void
    {
        if (! $proposal->hasMeaningfulNotes()) {
            $proposal->notes = $fallbackNote;
        }
    }

    /**
     * Mirrors WorkflowTransitionService::createFollowUpFromOrigin()'s exact
     * shape/convention (origin_type='proposal', assigned to the Proposal's
     * own assigned Employee) — deliberately a separate, small
     * implementation rather than a shared call, matching this codebase's
     * existing per-service precedent for this kind of small write.
     */
    private function createFollowUpFromProposal(
        Proposal $proposal,
        CarbonInterface $followUpAt,
        string $reason,
        ?string $notes,
        ?ContactMode $contactMode,
    ): FollowUp {
        $followUp = new FollowUp([
            'prospect_id' => $proposal->prospect_id,
            'user_id' => $proposal->assigned_to,
            'follow_up_at' => $followUpAt,
            'reason' => $reason,
            'notes' => $notes,
            'contact_mode' => $contactMode,
            'status' => 'pending',
        ]);
        $followUp->forceFill(['origin_type' => 'proposal', 'origin_id' => $proposal->getKey()]);
        $followUp->save();

        return $followUp;
    }

    /**
     * Clones a Sent ProposalVersion into a brand-new Draft — mirrors
     * ProposalVersionWorkflowService::cloneIntoNewDraft()'s shape, with one
     * deliberate difference (Section J): a LEGACY backfilled source never
     * has its totals fields carried over as authoritative commercial data
     * (a legacy row's grand_total is unverified historical data, and no
     * legacy row ever has lines to clone in the first place) — Submit's own
     * ProposalVersionCalculator recalculation is what must establish real
     * totals once real lines exist.
     */
    private function cloneSentVersionIntoDraft(Proposal $proposal, ProposalVersion $source): ProposalVersion
    {
        $nextVersionNumber = (int) DB::table('proposal_versions')
            ->where('proposal_id', $proposal->getKey())
            ->lockForUpdate()
            ->max('version_number') + 1;

        $snapshot = collect(self::CLONED_SNAPSHOT_FIELDS)
            ->mapWithKeys(fn (string $field) => [$field => $source->{$field}])
            ->all();

        if (! $source->is_legacy_backfill) {
            foreach (self::CLONED_TOTAL_FIELDS as $field) {
                $snapshot[$field] = $source->{$field};
            }
        }

        $newDraft = ProposalVersion::create(array_merge($snapshot, [
            'proposal_id' => $proposal->getKey(),
            'version_number' => $nextVersionNumber,
            'lifecycle_status' => ProposalVersionLifecycle::Draft,
            'is_legacy_backfill' => false,
        ]));

        if (! $source->is_legacy_backfill) {
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
        }

        return $newDraft;
    }

    /**
     * Section O: a retried/double-submitted click reusing the same
     * idempotency key must reuse the existing response silently (no
     * duplicate Follow-Up/Draft/billing handoff, no re-run outcome
     * transition); a fresh idempotency key attached to a MATERIALLY
     * DIFFERENT payload must be rejected outright. No dedicated
     * request_fingerprint column exists, so this compares the incoming
     * call's own arguments against the already-persisted row's (and its
     * linked FollowUp's, where relevant) material fields directly.
     */
    private function assertSamePayload(
        ProposalClientResponse $existing,
        ProposalVersion $version,
        ProposalClientResponseType $responseType,
        ?string $reason,
        ?string $notes,
        ?ProposalClientResponseNextAction $nextAction,
        ?CarbonInterface $followUpAt,
    ): void {
        $materiallyIdentical = $existing->proposal_version_id === $version->getKey()
            && $existing->response_type === $responseType
            && $existing->reason === $reason
            && $existing->notes === $notes
            && $existing->next_action === $nextAction;

        if ($followUpAt !== null) {
            $existingFollowUpAt = $existing->followUp?->follow_up_at;

            $materiallyIdentical = $materiallyIdentical
                && $existingFollowUpAt !== null
                && $existingFollowUpAt->timestamp === $followUpAt->timestamp;
        }

        if (! $materiallyIdentical) {
            throw new LogicException(
                "Idempotency key '{$existing->idempotency_key}' was already used for a different client response — use a fresh idempotency key for a new response."
            );
        }
    }
}
