<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Phase 4A-3.1: the outcome of one ProposalSend attempt. A manual send
 * (4A-3.3) is a staff assertion with no execution to fail — it always
 * resolves to Sent, never Failed; Failed is reserved for a genuine
 * provider/execution attempt (Graph, 4B) that was actually attempted and
 * did not succeed.
 */
enum ProposalSendStatus: string implements HasColor, HasLabel
{
    case Sent = 'sent';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sent => 'Sent',
            self::Failed => 'Failed',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Sent => 'success',
            self::Failed => 'danger',
        };
    }
}
