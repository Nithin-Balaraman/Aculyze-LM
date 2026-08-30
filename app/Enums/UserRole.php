<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The three-tier Senior Manager -> Manager -> Employee hierarchy (Phase 1
 * BA baseline). The PHP case name and stored value for the top tier remain
 * `Admin`/'admin' for backward compatibility (every existing isAdmin()
 * check, policy, test fixture and persisted row keeps working unchanged);
 * only the user-facing label changed, to "Senior Manager", matching the
 * approved business terminology. Admin/Senior Manager is still a superset
 * of every other tier (organization-wide visibility and final authority),
 * never a read-only reporting role. See AGENTS.md section 7.
 *
 * Deliberately a fixed three-tier enum, not a generic/configurable role
 * hierarchy — the Master BA Specification (Section 3.1) states the initial
 * three-level hierarchy is sufficient for the current baseline and a
 * no-code role builder is explicitly future/out-of-scope.
 */
enum UserRole: string implements HasLabel
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Employee = 'employee';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => 'Senior Manager',
            self::Manager => 'Manager',
            self::Employee => 'Employee',
        };
    }
}
