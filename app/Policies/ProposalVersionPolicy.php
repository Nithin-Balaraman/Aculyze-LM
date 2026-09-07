<?php

namespace App\Policies;

use App\Models\ProposalVersion;
use App\Models\User;
use App\Support\Authorization\HierarchyVisibility;

/**
 * Phase 4A-2.2: server-side authorization for the ProposalVersion
 * commercial workflow — reused both by ProposalVersionWorkflowService
 * itself (the actual, fail-closed enforcement; never trusts a future
 * button's visibility alone) and by any later Filament action gating.
 *
 * Access is always checked against the underlying Proposal's own
 * assigned_to/hierarchy scope (App\Support\Authorization\
 * HierarchyVisibility), never a separate "commercial_manager_id" —
 * commercial responsibility is dynamically derived from the Proposal's
 * current assigned Employee and their current Manager, exactly like every
 * other Proposal-related authorization in this codebase (locked Decision 7).
 */
class ProposalVersionPolicy
{
    /** Manager prepares/submits within their own hierarchy; Senior Manager, organization-wide. */
    public function submit(User $user, ProposalVersion $version): bool
    {
        return ($user->isManager() || $user->isSeniorManager())
            && HierarchyVisibility::canAccess($user, $version->proposal, 'assigned_to');
    }

    /**
     * Senior Manager only — final commercial authority. Never the same
     * actor recorded in submitted_by, even when that actor holds Senior
     * Manager rank (locked Decision 19 — self-approval is prohibited
     * regardless of role).
     */
    public function approve(User $user, ProposalVersion $version): bool
    {
        return $user->isSeniorManager()
            && HierarchyVisibility::canAccess($user, $version->proposal, 'assigned_to')
            && $version->submitted_by !== $user->id;
    }

    /** Senior Manager only — final commercial authority; no self-return restriction (only Decision 19's self-approval rule applies). */
    public function returnForRevision(User $user, ProposalVersion $version): bool
    {
        return $user->isSeniorManager()
            && HierarchyVisibility::canAccess($user, $version->proposal, 'assigned_to');
    }

    /** Manager-or-above, same hierarchy scope as submit() — Employee excluded (locked Decision 4 + addendum). */
    public function createRevision(User $user, ProposalVersion $version): bool
    {
        return ($user->isManager() || $user->isSeniorManager())
            && HierarchyVisibility::canAccess($user, $version->proposal, 'assigned_to');
    }
}
