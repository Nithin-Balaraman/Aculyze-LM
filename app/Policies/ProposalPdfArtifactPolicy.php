<?php

namespace App\Policies;

use App\Models\ProposalVersion;
use App\Models\User;
use App\Support\Authorization\HierarchyVisibility;

/**
 * Phase 4A-3.2/4A-3.3: server-side authorization for final Proposal PDF
 * generation/correction/review — reused both by ProposalPdfArtifactService
 * itself (fail-closed enforcement) and by the Filament actions that gate
 * button visibility. Access is always checked against the underlying
 * Proposal's own assigned_to/hierarchy scope, exactly like
 * ProposalVersionPolicy (locked Decision 7's precedent).
 *
 * Manager/Senior Manager retain unconditional review/download rights
 * (before or after Release — locked Decision, Section F). Employee gains
 * access only once ALL of Section F's conditions hold: the Version is its
 * Proposal's current Version, a Release exists, that Release is non-stale
 * (ProposalVersion::isReleaseStale(), 4A-3.1), and ordinary tenant/
 * assignment authorization passes. A PDF correction (4A-3.2) that produces
 * a new primary artifact makes the Release stale and immediately revokes
 * Employee access again, with no code here doing anything special — it
 * simply re-evaluates isReleaseStale() fresh every call.
 */
class ProposalPdfArtifactPolicy
{
    /** Manager-or-above may generate/retry, within hierarchy scope; Senior Manager, organization-wide. Legacy Versions are excluded at the service layer, not here (a structural fact, not a role rule). */
    public function generatePdf(User $user, ProposalVersion $version): bool
    {
        return ($user->isManager() || $user->isSeniorManager())
            && HierarchyVisibility::canAccess($user, $version->proposal, 'assigned_to');
    }

    /** Same rule as generatePdf() — correction is still a Manager-or-above action, never Employee. */
    public function correctPdf(User $user, ProposalVersion $version): bool
    {
        return ($user->isManager() || $user->isSeniorManager())
            && HierarchyVisibility::canAccess($user, $version->proposal, 'assigned_to');
    }

    /**
     * Manager/Senior Manager may review/download the current or any
     * historical successful artifact, regardless of Release state. An
     * assigned Employee may download only the CURRENT Version's PDF, and
     * only once it carries a valid (non-stale) Release — see class
     * docblock and Section F.
     */
    public function downloadPdf(User $user, ProposalVersion $version): bool
    {
        if (($user->isManager() || $user->isSeniorManager())
            && HierarchyVisibility::canAccess($user, $version->proposal, 'assigned_to')) {
            return true;
        }

        return $this->employeeCanAccessReleasedPdf($user, $version);
    }

    private function employeeCanAccessReleasedPdf(User $user, ProposalVersion $version): bool
    {
        if ($version->proposal->current_version_id !== $version->getKey()) {
            return false;
        }

        if ($version->released_at === null || $version->isReleaseStale()) {
            return false;
        }

        return HierarchyVisibility::canAccess($user, $version->proposal, 'assigned_to');
    }
}
