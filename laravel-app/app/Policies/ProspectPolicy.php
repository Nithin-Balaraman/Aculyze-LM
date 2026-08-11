<?php

namespace App\Policies;

use App\Models\Prospect;
use App\Models\User;

/**
 * Employees may fully manage Prospects currently assigned to them. Admin can
 * do everything, everywhere (AGENTS.md sections 6-7). Reassignment itself is
 * handled by App\Filament\Resources\ProspectResource's Assign action, gated
 * separately below via `assign()`.
 */
class ProspectPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Prospect $prospect): bool
    {
        return $user->isAdmin() || $prospect->assigned_to === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Prospect $prospect): bool
    {
        return $user->isAdmin() || $prospect->assigned_to === $user->id;
    }

    public function delete(User $user, Prospect $prospect): bool
    {
        return $user->isAdmin() || $prospect->assigned_to === $user->id;
    }

    /** Only Admin may assign/reassign a Prospect to an employee. */
    public function assign(User $user, Prospect $prospect): bool
    {
        return $user->isAdmin();
    }
}
