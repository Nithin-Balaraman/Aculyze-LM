<?php

namespace App\Policies;

use App\Models\Demo;
use App\Models\User;
use App\Support\Authorization\HierarchyVisibility;

/**
 * Phase 2: Demo follows the same authorization model as every other
 * organization-owned sales record (App\Enums\LeadStatus's/§8's approved
 * resolution) — Employee: own assigned Demos; Manager: own + direct
 * reports'; Senior Manager: organization-wide. Only Senior Manager may
 * delete, mirroring AppointmentPolicy/LeadPolicy.
 */
class DemoPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Demo $demo): bool
    {
        return HierarchyVisibility::canAccess($user, $demo, 'assigned_to');
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Demo $demo): bool
    {
        return HierarchyVisibility::canAccess($user, $demo, 'assigned_to');
    }

    public function delete(User $user, Demo $demo): bool
    {
        return $user->isSeniorManager();
    }

    /** Assign/reassign, including across teams — Manager and Senior Manager. */
    public function assign(User $user, Demo $demo): bool
    {
        return $user->isManager() || $user->isSeniorManager();
    }
}
