<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How a Follow-Up is planned to be reached — purely informational, no
 * routing/business logic keys off this (unlike CallOutcome).
 */
enum ContactMode: string implements HasLabel
{
    case Call = 'call';
    case Mail = 'mail';
    case Text = 'text';

    public function getLabel(): string
    {
        return match ($this) {
            self::Call => 'Call',
            self::Mail => 'Mail',
            self::Text => 'Text',
        };
    }
}
