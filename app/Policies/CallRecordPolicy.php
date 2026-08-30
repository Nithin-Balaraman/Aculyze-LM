<?php

namespace App\Policies;

use App\Models\CallRecord;
use App\Models\User;
use App\Support\Authorization\HierarchyVisibility;

/**
 * Call Records are the Activity Log and represent complete, historical call
 * coverage (AGENTS.md section 12). Employees may create and view their own
 * calls and correct clerical mistakes; Managers additionally see/update
 * their direct reports' (Phase 1 hierarchy — Master BA permission matrix
 * rows 18-19). Only Senior Manager (Admin) may delete a Call Record — it is
 * critical history and must not casually disappear (AGENTS.md section 37).
 */
class CallRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CallRecord $callRecord): bool
    {
        return HierarchyVisibility::canAccess($user, $callRecord, 'user_id');
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, CallRecord $callRecord): bool
    {
        return HierarchyVisibility::canAccess($user, $callRecord, 'user_id');
    }

    public function delete(User $user, CallRecord $callRecord): bool
    {
        return $user->isSeniorManager();
    }
}
