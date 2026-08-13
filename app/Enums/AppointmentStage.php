<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * PROVISIONAL — stage names and order are not yet confirmed by the business
 * (see AGENTS.md section 61, Question 1). They are centralized here so they
 * can be renamed, reordered, added, or removed without touching any other
 * file. Case order below is also the display/progression order.
 */
enum AppointmentStage: string implements HasColor, HasLabel
{
    case AppointmentMade = 'appointment_made';
    case VisitConducted = 'visit_conducted';
    case DiscussionCompleted = 'discussion_completed';
    case Succeeded = 'succeeded';
    case NotSucceeded = 'not_succeeded';

    public function getLabel(): string
    {
        return match ($this) {
            self::AppointmentMade => 'Appointment Made',
            self::VisitConducted => 'Visit / Meeting Conducted',
            self::DiscussionCompleted => 'Discussion Completed',
            self::Succeeded => 'Succeeded',
            self::NotSucceeded => 'Not Succeeded',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::AppointmentMade => 'gray',
            self::VisitConducted, self::DiscussionCompleted => 'info',
            self::Succeeded => 'success',
            self::NotSucceeded => 'danger',
        };
    }

    /** Terminal stages are excluded from "active" lists and never appear stale. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::NotSucceeded], true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])->all();
    }
}
