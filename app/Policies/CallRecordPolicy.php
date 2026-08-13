<?php

namespace App\Policies;

use App\Models\CallRecord;
use App\Models\User;

/**
 * Call Records are the Activity Log and represent complete, historical call
 * coverage (AGENTS.md section 12). Employees may create and view their own
 * calls and correct clerical mistakes, but only Admin may delete a Call
 * Record — it is critical history and must not casually disappear
 * (AGENTS.md section 37).
 */
class CallRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CallRecord $callRecord): bool
    {
        return $user->isAdmin() || $callRecord->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, CallRecord $callRecord): bool
    {
        return $user->isAdmin() || $callRecord->user_id === $user->id;
    }

    public function delete(User $user, CallRecord $callRecord): bool
    {
        return $user->isAdmin();
    }
}
