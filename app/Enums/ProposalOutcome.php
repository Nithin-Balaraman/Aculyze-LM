<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The final outcome of a Proposal. Null/unset means the Proposal is still
 * in progress (no final decision yet).
 *
 * Won and Lost are always closed/terminal (never stale). Hold's behavior is
 * an open business question — see AGENTS.md section 61, Question 6 — and is
 * kept configurable via config('aculyze.hold_is_terminal_for_staleness')
 * rather than hard-coded here, so it can change without touching this enum.
 */
enum ProposalOutcome: string implements HasColor, HasLabel
{
    case Won = 'won';
    case Hold = 'hold';
    case Lost = 'lost';

    public function getLabel(): string
    {
        return match ($this) {
            self::Won => 'Won',
            self::Hold => 'Hold',
            self::Lost => 'Lost',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Won => 'success',
            self::Hold => 'warning',
            self::Lost => 'danger',
        };
    }

    /**
     * Whether this outcome should exclude the Proposal from stale-alert lists.
     * TODO(business decision): Hold's behavior is unresolved — see class docblock.
     */
    public function isTerminalForStaleness(): bool
    {
        return match ($this) {
            self::Won, self::Lost => true,
            self::Hold => (bool) config('aculyze.hold_is_terminal_for_staleness'),
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
