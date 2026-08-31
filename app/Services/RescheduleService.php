<?php

namespace App\Services;

use App\Models\Concerns\Reschedulable;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Phase 2: the single, history-preserving mechanism for rescheduling a
 * not-yet-conducted Follow-Up, Appointment, or Demo — "old activity
 * becomes Rescheduled/history, new activity becomes the active Pending/
 * Scheduled record" (Master BA approved rule).
 *
 * Deliberately NOT used for "repeat activity after a completed one" (e.g.
 * Appointment/Demo outcome = Another Appointment/Demo Required) — that is
 * a different business event handled entirely by
 * App\Services\WorkflowTransitionService, which marks the prior record
 * Completed (never Rescheduled) and links the new one via `origin_type`/
 * `origin_id` (lineage), never `rescheduled_from_id`. See both services'
 * class docblocks for the full distinction.
 *
 * Every call site — Filament Resources, Edit pages, row actions, the
 * Pipeline Board — must call reschedule() rather than updating the
 * scheduled-datetime column directly, so the history-preserving behavior
 * can never be silently bypassed (every model's own
 * App\Models\Concerns\GuardsScheduleAgainstDirectEdit guard is the
 * backstop that rejects a direct write once a record is terminal).
 */
class RescheduleService
{
    /**
     * @template TModel of Model
     * @param  TModel&Reschedulable  $activity
     * @param  array<string, mixed>  $newScheduleData  must include the new value for $activity->scheduledAtColumn()
     * @return TModel&Reschedulable
     */
    public function reschedule(Model&Reschedulable $activity, array $newScheduleData, ?string $reason = null): Model
    {
        return DB::transaction(function () use ($activity, $newScheduleData, $reason) {
            $class = get_class($activity);

            // Lock and re-read inside the transaction so two concurrent
            // reschedule requests for the same record can't both pass the
            // "still active" check below (mirrors
            // CallRoutingService::route()'s exact locking pattern).
            /** @var Model&Reschedulable $locked */
            $locked = $class::query()->whereKey($activity->getKey())->lockForUpdate()->firstOrFail();

            $activeValue = $locked->activeStatusValue();

            if ($locked->status?->value !== $activeValue->value) {
                throw new LogicException(
                    class_basename($locked)." #{$locked->getKey()} cannot be rescheduled: its status is ".
                    ($locked->status?->value ?? 'unset')." — only an active ({$activeValue->value}) record can be rescheduled."
                );
            }

            $scheduleColumn = $locked->scheduledAtColumn();
            $newScheduledAt = $newScheduleData[$scheduleColumn] ?? null;

            if (blank($newScheduledAt)) {
                throw new LogicException("A new {$scheduleColumn} is required to reschedule.");
            }

            $oldScheduledAt = $locked->{$scheduleColumn};

            $replacement = new $class(array_merge(
                $locked->replacementAttributesForReschedule(),
                $newScheduleData,
            ));
            $replacement->forceFill([
                'organization_id' => $locked->organization_id,
                'status' => $activeValue->value,
                'rescheduled_from_id' => $locked->getKey(),
            ]);
            $replacement->save();

            $locked->forceFill(['status' => $locked->rescheduledStatusValue()->value])->save();

            AuditLogger::record(
                entityType: class_basename($locked),
                entityId: $locked->getKey(),
                action: 'rescheduled',
                organizationId: $locked->organization_id,
                before: [$scheduleColumn => self::normalizeForAudit($oldScheduledAt)],
                after: [
                    $scheduleColumn => self::normalizeForAudit($replacement->{$scheduleColumn}),
                    'replacement_id' => $replacement->getKey(),
                    'original_id' => $locked->getKey(),
                ],
                description: $reason,
            );

            return $replacement;
        });
    }

    private static function normalizeForAudit(mixed $value): mixed
    {
        return $value instanceof \DateTimeInterface ? $value->toIso8601String() : $value;
    }
}
