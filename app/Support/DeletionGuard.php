<?php

namespace App\Support;

use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Change Request Sections 5, 8, 9: every foreign key in this schema is a
 * plain RESTRICT constraint (see AGENTS.md), so deleting a User, Call
 * Record, or Lead that still has related records would otherwise fail as a
 * raw, uncaught 500. Rather than cascade-deleting real business history or
 * silently soft-deleting instead, this blocks the delete up front with a
 * friendly notification naming what's still attached.
 *
 * Models expose their own `deletionBlockers(): array<string, int>` (label
 * => count, already meaningful per-model) so this stays a single reusable
 * check rather than duplicated per-resource logic.
 */
class DeletionGuard
{
    /**
     * Blocks deleting a single record if it has any related records.
     * Intended for a Filament DeleteAction's `->before()` hook.
     */
    public static function guardRecord(Model $record, string $subjectLabel): void
    {
        $blockers = array_filter($record->deletionBlockers());

        if ($blockers === []) {
            return;
        }

        Notification::make()
            ->title("Can't delete this {$subjectLabel}")
            ->body(self::message($blockers, $record))
            ->danger()
            ->send();

        throw new Halt;
    }

    /**
     * Blocks an entire bulk delete if ANY selected record has related
     * records — a partial delete (some go, some silently don't) would be
     * more confusing than just naming what's blocking it and trying again.
     * Intended for a Filament DeleteBulkAction's `->before()` hook.
     *
     * @param  Collection<int, Model>  $records
     */
    public static function guardRecords(Collection $records, string $subjectLabelPlural, \Closure $describe): void
    {
        $blocked = $records
            ->filter(fn (Model $record) => array_filter($record->deletionBlockers()) !== [])
            ->map($describe)
            ->values();

        if ($blocked->isEmpty()) {
            return;
        }

        Notification::make()
            ->title("Can't delete {$blocked->count()} of the selected {$subjectLabelPlural}")
            ->body('These still have related records and were left untouched — reassign or remove those first, then try again: '.$blocked->implode(', ').'.')
            ->danger()
            ->send();

        throw new Halt;
    }

    /**
     * "Reassign or remove those first" is the right advice for most
     * blockers, but not for permanent history that is never removed at all
     * (a Proposal's commercial Versions, a Lead's Demos). Audit fix pass 1
     * (Section 7) therefore lets a model replace that closing sentence with
     * its own domain-accurate one via an optional
     * `deletionBlockerAdvice(): string`, while the itemized counts above it
     * stay identical everywhere.
     *
     * @param  array<string, int>  $blockers
     */
    private static function message(array $blockers, Model $record): string
    {
        $total = array_sum($blockers);
        $breakdown = collect($blockers)
            ->map(fn (int $count, string $label) => "{$count} {$label}")
            ->implode(', ');

        $advice = method_exists($record, 'deletionBlockerAdvice')
            ? $record->deletionBlockerAdvice()
            : 'Reassign or remove those first.';

        return "It still has {$total} related record(s) — {$breakdown}. {$advice}";
    }
}
