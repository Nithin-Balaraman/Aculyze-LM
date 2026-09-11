<?php

namespace App\Policies;

use App\Models\ProposalVersion;
use App\Models\User;
use App\Support\Authorization\HierarchyVisibility;

/**
 * Phase 4A-3.3, Section C: server-side authorization for releasing a
 * ProposalVersion's current successful PDF artifact for client sending.
 * Manager-or-above only, within the same hierarchy/tenant scope as every
 * other Proposal-commercial policy — Employee can never Release, and a
 * Senior Manager who approved the Version may also Release it (no
 * approver-cannot-release restriction, unlike Approve's self-approval
 * rule).
 */
class ProposalReleasePolicy
{
    public function release(User $user, ProposalVersion $version): bool
    {
        return ($user->isManager() || $user->isSeniorManager())
            && HierarchyVisibility::canAccess($user, $version->proposal, 'assigned_to');
    }
}
