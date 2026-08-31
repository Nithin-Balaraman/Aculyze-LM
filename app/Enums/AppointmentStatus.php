<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Phase 2: the Appointment's own lifecycle state, separate from
 * App\Enums\AppointmentOutcome (what business result was recorded) and
 * from the legacy App\Enums\AppointmentStage (kept, untouched, read-only —
 * see the legacy-backfill command's docblock for the exact mapping used).
 *
 * "Due Today"/"Overdue" are deliberately NOT case values here — they are
 * computed from `appointment_at` vs now() while status is Scheduled (see
 * Appointment::isOverdue()/isDueToday()), never stored, so there is no
 * background job required to keep them current and no duplicated state.
 */
enum AppointmentStatus: string implements HasColor, HasLabel
{
    case Scheduled = 'scheduled';
    case Rescheduled = 'rescheduled';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Rescheduled => 'Rescheduled',
            self::Cancelled => 'Cancelled',
            self::Completed => 'Completed',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Scheduled => 'warning',
            self::Rescheduled => 'gray',
            self::Cancelled => 'gray',
            self::Completed => 'success',
        };
    }

    /** Historical/terminal — never re-enterable, never the active card on the Pipeline Board. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Rescheduled, self::Cancelled, self::Completed], true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])->all();
    }

    /**
     * The single source of truth for the approved conservative legacy
     * `stage` -> `status` mapping — used by both
     * App\Console\Commands\BackfillLeadAppointmentStatus (existing rows)
     * and any code path that creates an Appointment directly at a given
     * legacy stage (e.g. PipelineBoard's cross-drop destination creation)
     * without an explicit status of its own, so the two can never
     * diverge. Throws on any stage value outside the exact known set
     * rather than guessing — mirrors the backfill command's own
     * hard-fail-on-unknown-value behavior.
     */
    public static function fromLegacyStage(AppointmentStage|string $stage): self
    {
        $value = $stage instanceof AppointmentStage ? $stage->value : $stage;

        return match ($value) {
            AppointmentStage::AppointmentMade->value => self::Scheduled,
            AppointmentStage::VisitConducted->value,
            AppointmentStage::DiscussionCompleted->value,
            AppointmentStage::Succeeded->value,
            AppointmentStage::NotSucceeded->value => self::Completed,
            default => throw new \ValueError("Unrecognized legacy Appointment stage '{$value}' — no known status mapping."),
        };
    }
}
