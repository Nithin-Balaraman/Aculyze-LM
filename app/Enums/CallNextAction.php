<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Phase 3: the explicit, constrained next-action decision required when
 * CallOutcome::Other is recorded — meaningful ONLY for that one outcome.
 * Every other CallOutcome has its own fixed, approved routing (see
 * CallOutcome's routing methods) and must never carry a next_action value.
 *
 * Call Outcome = what happened; Call Next Action = what we intentionally
 * decided to do next — two separate concepts, never merged.
 */
enum CallNextAction: string implements HasColor, HasLabel
{
    case NoFurtherAction = 'no_further_action';
    case CreateFollowUp = 'create_follow_up';
    case CreateAppointment = 'create_appointment';
    case CreateLead = 'create_lead';

    public function getLabel(): string
    {
        return match ($this) {
            self::NoFurtherAction => 'No Further Action',
            self::CreateFollowUp => 'Create Follow-Up',
            self::CreateAppointment => 'Create Appointment',
            self::CreateLead => 'Create Lead',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::NoFurtherAction => 'gray',
            self::CreateFollowUp, self::CreateAppointment => 'warning',
            self::CreateLead => 'success',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])->all();
    }
}
