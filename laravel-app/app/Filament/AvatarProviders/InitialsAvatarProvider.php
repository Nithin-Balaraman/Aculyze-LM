<?php

namespace App\Filament\AvatarProviders;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * Renders a brand-navy initials avatar as an inline SVG data URI instead of
 * Filament's default (a request to ui-avatars.com). Keeps the topbar
 * self-contained — no external image dependency to fail or slow down on.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        $initial = strtoupper(substr((string) $record->name, 0, 1)) ?: '?';

        $svg = <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40">
                <rect width="40" height="40" rx="20" fill="#0E1131" />
                <text x="20" y="21" text-anchor="middle" dominant-baseline="central"
                    font-family="ui-sans-serif, system-ui, sans-serif" font-size="16"
                    font-weight="600" fill="#2DC4ED">{$initial}</text>
            </svg>
            SVG;

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
