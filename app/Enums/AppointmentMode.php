<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Pipeline Board V2 (Calls column, locked design section 4A): how an
 * Appointment scheduled from the Calls -> Appointment destination modal
 * will take place. Only In-Person requires a Location — see
 * requiresLocation().
 */
enum AppointmentMode: string implements HasColor, HasLabel
{
    case InPerson = 'in_person';
    case Online = 'online';
    case PhoneCall = 'phone_call';

    public function getLabel(): string
    {
        return match ($this) {
            self::InPerson => 'In-Person',
            self::Online => 'Online',
            self::PhoneCall => 'Phone Call',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::InPerson => 'warning',
            self::Online => 'info',
            self::PhoneCall => 'gray',
        };
    }

    public function requiresLocation(): bool
    {
        return $this === self::InPerson;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])->all();
    }
}
