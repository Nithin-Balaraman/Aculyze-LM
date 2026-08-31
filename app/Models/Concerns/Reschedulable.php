<?php

namespace App\Models\Concerns;

/**
 * The contract App\Services\RescheduleService relies on to reschedule any
 * of the three schedulable activity types (Follow-Up, Appointment, Demo)
 * generically, without hardcoding per-model column/enum knowledge inside
 * the service itself.
 */
interface Reschedulable
{
    /** The column holding this activity's scheduled date/time. */
    public function scheduledAtColumn(): string;

    /** The backed enum class for this model's `status` column. */
    public function statusEnumClass(): string;

    /** The single status value considered "active"/reschedulable (e.g. Pending, Scheduled). */
    public function activeStatusValue(): \BackedEnum;

    /** The single status value this model's status becomes once superseded by an explicit Reschedule. */
    public function rescheduledStatusValue(): \BackedEnum;

    /**
     * The known, already-established fields to carry forward onto the
     * replacement record (Company/Lead/product/contact/assignment info) —
     * explicit per model, deliberately never a blanket attribute copy, so
     * schedule/status/lineage columns can never leak across by accident.
     *
     * @return array<string, mixed>
     */
    public function replacementAttributesForReschedule(): array;
}
