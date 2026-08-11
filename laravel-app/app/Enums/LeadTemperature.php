<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum LeadTemperature: string implements HasColor, HasIcon, HasLabel
{
    case Hot = 'hot';
    case Warm = 'warm';
    case Cold = 'cold';

    public function getLabel(): string
    {
        return match ($this) {
            self::Hot => 'Hot',
            self::Warm => 'Warm',
            self::Cold => 'Cold',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Hot => 'danger',
            self::Warm => 'warning',
            self::Cold => 'info',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Hot => 'heroicon-o-fire',
            self::Warm => 'heroicon-o-sun',
            self::Cold => 'heroicon-o-cloud',
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
