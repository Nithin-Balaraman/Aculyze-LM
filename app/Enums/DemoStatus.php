<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Phase 2: Demo's own lifecycle state, separate from App\Enums\DemoOutcome
 * (what happened) and App\Enums\DemoNextAction (what was decided next).
 *
 * "Due Today"/"Overdue" are computed from `demo_at` vs now() while status
 * is Scheduled — never stored (see the same reasoning on
 * App\Enums\AppointmentStatus).
 */
enum DemoStatus: string implements HasColor, HasLabel
{
    case Scheduled = 'scheduled';
    case Rescheduled = 'rescheduled';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Rescheduled => 'Rescheduled',
            self::Cancelled => 'Cancelled',
            self::Completed => 'Completed',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Scheduled => 'warning',
            self::Rescheduled => 'gray',
            self::Cancelled => 'gray',
            self::Completed => 'success',
        };
    }

    /** Historical/terminal — never re-enterable. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Rescheduled, self::Cancelled, self::Completed], true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])->all();
    }
}
