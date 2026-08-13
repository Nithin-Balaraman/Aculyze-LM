<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

/**
 * Only Admin may delete a Lead — it is critical sales history (AGENTS.md
 * section 37). Employees fully manage leads assigned to them, including
 * advancing the stage and temperature.
 */
class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Lead $lead): bool
    {
        return $user->isAdmin() || $lead->assigned_to === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Lead $lead): bool
    {
        return $user->isAdmin() || $lead->assigned_to === $user->id;
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $user->isAdmin();
    }

    public function assign(User $user, Lead $lead): bool
    {
        return $user->isAdmin();
    }
}
