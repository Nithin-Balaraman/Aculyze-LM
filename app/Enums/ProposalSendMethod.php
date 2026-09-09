<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Phase 4A-3.1: how a ProposalSend was carried out. Manual (4A-3.3) is a
 * staff assertion — Aculyze-LM does not itself deliver the email — while
 * Graph (4B, not yet implemented) is real provider-executed delivery via
 * Microsoft Graph/Outlook. Both methods may exist against the same
 * unchanged Version (locked Decision 23).
 */
enum ProposalSendMethod: string implements HasLabel
{
    case Manual = 'manual';
    case Graph = 'graph';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Graph => 'Graph',
        };
    }
}
