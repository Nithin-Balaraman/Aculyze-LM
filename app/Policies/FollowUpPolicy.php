<?php

namespace App\Policies;

use App\Models\FollowUp;
use App\Models\User;
use App\Support\Authorization\HierarchyVisibility;

/**
 * Employees fully manage (including delete) Follow-Ups assigned to them;
 * Managers additionally manage their direct reports' (Phase 1 hierarchy —
 * Master BA permission matrix rows 18-19); Senior Manager manages every
 * Follow-Up in the organization.
 */
class FollowUpPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, FollowUp $followUp): bool
    {
        return HierarchyVisibility::canAccess($user, $followUp, 'user_id');
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, FollowUp $followUp): bool
    {
        return HierarchyVisibility::canAccess($user, $followUp, 'user_id');
    }

    public function delete(User $user, FollowUp $followUp): bool
    {
        return HierarchyVisibility::canAccess($user, $followUp, 'user_id');
    }
}
