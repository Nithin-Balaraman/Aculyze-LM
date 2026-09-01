<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Phase 3: structured tracking of whether a Company Profile has actually
 * been sent for a CallOutcome::ProfileRequested call — see CallRecord's
 * profile_sent_* columns and their validation rules.
 */
enum ProfileSentStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Sent = 'sent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Sent => 'Sent',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Sent => 'success',
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
