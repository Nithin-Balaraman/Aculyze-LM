<?php

namespace App\Services;

use App\Enums\ProposalVersionLifecycle;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\User;
use App\Policies\ProposalReleasePolicy;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Phase 4A-3.3 (Sections C/D): the controlled hand-off of a ProposalVersion's
 * current successful PDF artifact into a Release binding, ready for
 * assigned-Employee download and Manual Send. Release is metadata layered
 * on top of the existing lifecycle — never a new lifecycle value, never a
 * second commercial approval, never a Proposal outcome, never a client
 * response (locked Section C).
 *
 * Staleness is derived (ProposalVersion::isReleaseStale(), 4A-3.1), never
 * toggled here: a PDF correction (4A-3.2) that produces a new primary
 * artifact automatically makes a prior Release stale without this service
 * doing anything at all. The next call to release() simply re-binds the
 * new current primary artifact — recorded as a Re-Release audit event, but
 * otherwise an identical write to the very first Release.
 */
class ProposalReleaseService
{
    /**
     * Manager-or-above only. Current Version, Approved or Sent, non-legacy,
     * with an existing current successful primary PDF artifact. Re-callable
     * any number of times (first Release, or Re-Release after a PDF
     * correction made the prior one stale) — every call simply (re)binds
     * whatever the current primary artifact is right now.
     */
    public function release(ProposalVersion $version, User $actor, ?string $comment = null): ProposalVersion
    {
        return DB::transaction(function () use ($version, $actor, $comment) {
            [, $locked] = $this->lockProposalAndVersion($version);

            if (! in_array($locked->lifecycle_status, [ProposalVersionLifecycle::Approved, ProposalVersionLifecycle::Sent], true)) {
                throw new LogicException(
                    "ProposalVersion #{$locked->getKey()} cannot be released: its lifecycle is {$locked->lifecycle_status->value}, not approved or sent."
                );
            }

            if ($locked->is_legacy_backfill) {
                throw new LogicException(
                    "ProposalVersion #{$locked->getKey()} is a legacy backfilled Version — it can never be Released for client sending."
                );
            }

            if (! app(ProposalReleasePolicy::class)->release($actor, $locked)) {
                throw new LogicException("You are not authorized to release ProposalVersion #{$locked->getKey()} for client sending.");
            }

            $currentPrimary = app(ProposalPdfArtifactService::class)->currentPrimary($locked);

            if ($currentPrimary === null) {
                throw new LogicException(
                    "ProposalVersion #{$locked->getKey()} has no current successful final PDF artifact — generate one before releasing."
                );
            }

            $wasAlreadyReleased = $locked->released_at !== null;

            $locked->forceFill([
                'released_at' => now(),
                'released_by' => $actor->getKey(),
                'released_pdf_artifact_id' => $currentPrimary->getKey(),
                'release_comment' => $comment,
            ])->save();

            AuditLogger::record(
                entityType: 'ProposalVersion',
                entityId: $locked->getKey(),
                action: $wasAlreadyReleased ? 'proposal_version_re_released' : 'proposal_version_released',
                organizationId: $locked->organization_id,
                after: [
                    'released_pdf_artifact_id' => $currentPrimary->getKey(),
                    'release_comment' => $comment,
                ],
            );

            return $locked;
        });
    }

    /**
     * Mirrors ProposalVersionWorkflowService::lockProposalAndVersion()
     * exactly — same locked-current-version invariant, deliberately
     * duplicated here rather than shared, matching this codebase's existing
     * per-service precedent (WorkflowTransitionService/RescheduleService/
     * ProposalVersionWorkflowService each keep their own copy).
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

        $locked->setRelation('proposal', $proposal);

        return [$proposal, $locked];
    }
}
