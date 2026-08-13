<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The two roles supported by Aculyze-LM.
 *
 * Admin is a superset of Employee (admin = salesperson + org-wide management),
 * never a read-only reporting role. See AGENTS.md section 7.
 */
enum UserRole: string implements HasLabel
{
    case Admin = 'admin';
    case Employee = 'employee';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Employee => 'Employee',
        };
    }
}
