<?php

namespace App\Policies;

use App\Models\Proposal;
use App\Models\User;

/**
 * Only Admin may delete a Proposal — it is critical sales history
 * (AGENTS.md section 37). Employees fully manage proposals assigned to
 * them, including advancing the stage and recording the final outcome.
 */
class ProposalPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Proposal $proposal): bool
    {
        return $user->isAdmin() || $proposal->assigned_to === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Proposal $proposal): bool
    {
        return $user->isAdmin() || $proposal->assigned_to === $user->id;
    }

    public function delete(User $user, Proposal $proposal): bool
    {
        return $user->isAdmin();
    }

    public function assign(User $user, Proposal $proposal): bool
    {
        return $user->isAdmin();
    }
}
