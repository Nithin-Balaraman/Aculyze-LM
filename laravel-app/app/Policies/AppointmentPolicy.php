<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;

/**
 * Only Admin may delete an Appointment — it is critical sales history
 * (AGENTS.md section 37). Employees fully manage appointments assigned to
 * them, including advancing the stage.
 */
class AppointmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return $user->isAdmin() || $appointment->assigned_to === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Appointment $appointment): bool
    {
        return $user->isAdmin() || $appointment->assigned_to === $user->id;
    }

    public function delete(User $user, Appointment $appointment): bool
    {
        return $user->isAdmin();
    }

    public function assign(User $user, Appointment $appointment): bool
    {
        return $user->isAdmin();
    }
}
