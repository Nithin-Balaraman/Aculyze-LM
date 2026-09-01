<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Phase 3: how a Company Profile was sent, for a CallOutcome::ProfileRequested
 * call whose profile_sent_status is Sent. See CallRecord's validation rules —
 * Other requires profile_sent_notes explaining the mode.
 */
enum ProfileSentMode: string implements HasColor, HasLabel
{
    case Email = 'email';
    case WhatsApp = 'whatsapp';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::WhatsApp => 'WhatsApp',
            self::Other => 'Other',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Email => 'info',
            self::WhatsApp => 'success',
            self::Other => 'gray',
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
