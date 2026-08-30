<?php

namespace App\Policies;

use App\Models\Proposal;
use App\Models\User;
use App\Support\Authorization\HierarchyVisibility;

/**
 * Only Senior Manager (Admin) may delete a Proposal — it is critical sales
 * history (AGENTS.md section 37). Employees fully manage proposals assigned
 * to them; Managers additionally manage their direct reports' (Phase 1
 * hierarchy — Master BA permission matrix rows 18-20), including advancing
 * the stage and recording the final outcome.
 */
class ProposalPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Proposal $proposal): bool
    {
        return HierarchyVisibility::canAccess($user, $proposal, 'assigned_to');
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Proposal $proposal): bool
    {
        return HierarchyVisibility::canAccess($user, $proposal, 'assigned_to');
    }

    public function delete(User $user, Proposal $proposal): bool
    {
        return $user->isSeniorManager();
    }

    /** Assign/reassign, including across teams — Manager and Senior Manager (permission matrix rows 44-45). */
    public function assign(User $user, Proposal $proposal): bool
    {
        return $user->isManager() || $user->isSeniorManager();
    }
}
