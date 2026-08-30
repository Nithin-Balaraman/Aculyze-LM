<?php

namespace App\Policies;

use App\Models\Prospect;
use App\Models\User;
use App\Support\Authorization\HierarchyVisibility;

/**
 * Employees may fully manage Prospects currently assigned to them; Managers
 * additionally manage their direct reports' (Phase 1 hierarchy — Master BA
 * permission matrix rows 18-19); Senior Manager can do everything, org-wide
 * (AGENTS.md sections 6-7). Reassignment itself is handled by
 * App\Filament\Resources\ProspectResource's Assign action, gated separately
 * below via `assign()`.
 */
class ProspectPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Prospect $prospect): bool
    {
        return HierarchyVisibility::canAccess($user, $prospect, 'assigned_to');
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Prospect $prospect): bool
    {
        return HierarchyVisibility::canAccess($user, $prospect, 'assigned_to');
    }

    public function delete(User $user, Prospect $prospect): bool
    {
        return HierarchyVisibility::canAccess($user, $prospect, 'assigned_to');
    }

    /** Assign/reassign, including across teams — Manager and Senior Manager (permission matrix rows 44-45). */
    public function assign(User $user, Prospect $prospect): bool
    {
        return $user->isManager() || $user->isSeniorManager();
    }
}
