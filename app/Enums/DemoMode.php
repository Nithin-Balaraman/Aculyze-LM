<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Phase 2 approved baseline — On-site and Online only. Do not add Hybrid
 * or other values unless a later, separate business decision approves it.
 */
enum DemoMode: string implements HasColor, HasLabel
{
    case OnSite = 'on_site';
    case Online = 'online';

    public function getLabel(): string
    {
        return match ($this) {
            self::OnSite => 'On-site',
            self::Online => 'Online',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::OnSite => 'info',
            self::Online => 'success',
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
