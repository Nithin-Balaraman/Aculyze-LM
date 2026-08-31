<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum FollowUpStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * Phase 2: set only by App\Services\RescheduleService when this
     * Follow-Up's schedule changes before it was ever completed — never by
     * a normal Edit, and never for a "repeat activity after a completed
     * one" case (that stays Completed; see the service's own docblock).
     */
    case Rescheduled = 'rescheduled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Rescheduled => 'Rescheduled',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Completed => 'success',
            self::Cancelled => 'gray',
            self::Rescheduled => 'gray',
        };
    }

    /** Historical/terminal statuses — never re-enterable, never the active card on the Pipeline Board. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::Rescheduled], true);
    }
}
