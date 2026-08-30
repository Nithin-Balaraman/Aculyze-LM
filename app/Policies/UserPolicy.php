<?php

namespace App\Policies;

use App\Models\User;

/**
 * Employee accounts are managed exclusively by Senior Manager/Admin
 * (AGENTS.md sections 8, 39) — creating, editing and deleting accounts is
 * not delegated to Manager in Phase 1 (the Master BA permission matrix
 * grants Manager team *visibility*, rows 18-22, not account-management
 * authority, which stays Senior-Manager-only per rows 46-49). A user may
 * always view their own dashboard/profile, which is checked separately by
 * App\Filament\Pages\EmployeeDashboard rather than through this policy's
 * view() method (the Employee Management *resource* itself is
 * Senior-Manager-only, see UserResource::canAccess()). view() additionally
 * lets a Manager view their own direct reports' account records, matching
 * row 18's "view records of Employees reporting to them."
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSeniorManager();
    }

    public function view(User $user, User $model): bool
    {
        return $user->isSeniorManager()
            || $user->id === $model->id
            || ($user->isManager() && $model->manager_id === $user->id);
    }

    public function create(User $user): bool
    {
        return $user->isSeniorManager();
    }

    public function update(User $user, User $model): bool
    {
        return $user->isSeniorManager();
    }

    public function delete(User $user, User $model): bool
    {
        return $user->isSeniorManager() && $user->id !== $model->id;
    }
}
