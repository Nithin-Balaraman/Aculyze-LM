<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;
use App\Support\Authorization\HierarchyVisibility;

/**
 * Only Senior Manager (Admin) may delete an Appointment — it is critical
 * sales history (AGENTS.md section 37). Employees fully manage appointments
 * assigned to them; Managers additionally manage their direct reports'
 * (Phase 1 hierarchy — Master BA permission matrix rows 18-19), including
 * advancing the stage.
 */
class AppointmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return HierarchyVisibility::canAccess($user, $appointment, 'assigned_to');
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Appointment $appointment): bool
    {
        return HierarchyVisibility::canAccess($user, $appointment, 'assigned_to');
    }

    public function delete(User $user, Appointment $appointment): bool
    {
        return $user->isSeniorManager();
    }

    /** Assign/reassign, including across teams — Manager and Senior Manager (permission matrix rows 44-45). */
    public function assign(User $user, Appointment $appointment): bool
    {
        return $user->isManager() || $user->isSeniorManager();
    }
}
