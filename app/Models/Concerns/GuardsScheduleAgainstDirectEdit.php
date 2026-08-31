<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * Phase 2: normal Edit must never silently overwrite the scheduled
 * date/time of an EXISTING Follow-Up/Appointment/Demo — only the explicit
 * Reschedule action (App\Services\RescheduleService) may, and only that
 * service ever needs to. Scoped narrowly to the scheduled-datetime column
 * only (never a blanket "no writes on a historical record" guard) so it
 * can never block legitimate writes to other fields — Reassign, Mark
 * Lost, administrative/system maintenance — on any record regardless of
 * status; see each model's own immutability rationale in its class
 * docblock.
 *
 * Only blocks a genuine RESCHEDULE-shaped change: an already-set schedule
 * being changed to a different value. Filling in a still-NULL schedule
 * for the first time (e.g. a "No Answer" call auto-routes a Follow-Up
 * with no callback time yet — see App\Services\CallRoutingService — and
 * the real callback time is set once it's known) is not a reschedule and
 * is never blocked here; there is no prior schedule being replaced.
 *
 * RescheduleService is the only legitimate way to change an ALREADY-SET
 * schedule, and it never does so directly itself (it only ever sets
 * `status`/`status_changed_at` on the OLD record and creates a brand-new
 * row for the replacement), so this check needs no bypass flag the way
 * App\Support\Tenancy\Tenancy's does. The Filament Resources pair this
 * with `disabled()`/`dehydrated(false)` on the same column for any
 * existing record whose schedule is already set (see FollowUpResource/
 * AppointmentResource's formSchema()), so the field is excluded from
 * $data entirely before this guard would ever need to fire from a normal
 * Edit — this is the defense-in-depth backstop for any other/future
 * write path.
 */
trait GuardsScheduleAgainstDirectEdit
{
    public static function bootGuardsScheduleAgainstDirectEdit(): void
    {
        static::saving(function ($model): void {
            if (! $model->exists) {
                return;
            }

            $column = $model->scheduledAtColumn();

            if ($model->isDirty($column) && $model->getOriginal($column) !== null) {
                throw new LogicException(
                    static::class." cannot change an already-set {$column} directly — ".
                    'use the Reschedule action instead.'
                );
            }
        });
    }

    /** The column holding this activity's scheduled date/time. */
    abstract protected function scheduledAtColumn(): string;
}
