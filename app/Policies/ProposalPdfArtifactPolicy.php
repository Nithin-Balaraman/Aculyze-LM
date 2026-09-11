<?php

namespace App\Policies;

use App\Models\ProposalVersion;
use App\Models\User;
use App\Support\Authorization\HierarchyVisibility;

/**
 * Phase 4A-3.2: server-side authorization for final Proposal PDF
 * generation/correction/review — reused both by ProposalPdfArtifactService
 * itself (fail-closed enforcement) and by the Filament actions that gate
 * button visibility. Access is always checked against the underlying
 * Proposal's own assigned_to/hierarchy scope, exactly like
 * ProposalVersionPolicy (locked Decision 7's precedent).
 *
 * Employee has no ability here at all (locked Decision 5): final-PDF
 * access before a valid Release is Manager/Senior-Manager only, and no
 * Release exists yet in 4A-3.2 — so Employee download stays denied
 * entirely for the whole of this phase, not merely "pre-Release".
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

    /** Manager/Senior Manager may review/download the current or any historical successful artifact. Employee: never, in 4A-3.2 (no Release exists yet — see class docblock). */
    public function downloadPdf(User $user, ProposalVersion $version): bool
    {
        return ($user->isManager() || $user->isSeniorManager())
            && HierarchyVisibility::canAccess($user, $version->proposal, 'assigned_to');
    }
}
