<?php

namespace App\Policies;

use App\Models\User;

/**
 * Employee accounts are managed exclusively by Admin (AGENTS.md sections
 * 8, 39). A user may always view their own dashboard/profile, which is
 * checked separately by App\Filament\Pages\EmployeeDashboard rather than
 * through this policy's view() method (the Employee Management *resource*
 * itself is admin-only, see UserResource::canAccess()).
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, User $model): bool
    {
        return $user->isAdmin() || $user->id === $model->id;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, User $model): bool
    {
        return $user->isAdmin() && $user->id !== $model->id;
    }
}
