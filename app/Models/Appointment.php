<?php

namespace App\Models;

use App\Enums\AppointmentStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Date;

class Appointment extends Model
{
    use HasFactory;

    protected $fillable = [
        'prospect_id',
        'call_record_id',
        'assigned_to',
        'created_by',
        'appointment_at',
        'stage',
        'meeting_notes',
        'outcome_notes',
    ];

    protected function casts(): array
    {
        return [
            'stage' => AppointmentStage::class,
            'appointment_at' => 'datetime',
            'stage_changed_at' => 'datetime',
            'is_lost' => 'boolean',
            'lost_at_stage' => AppointmentStage::class,
            'lost_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $appointment) {
            // stage_changed_at must only move when the stage itself
            // changes — never on unrelated edits like notes (AGENTS.md
            // section 19).
            if ($appointment->isDirty('stage') || ! $appointment->exists) {
                $appointment->stage_changed_at = Date::now();
            }

            // Appointment At is mandatory (Change Request: Mandatory
            // Fields batch) — the Filament form already blocks this
            // interactively (see AppointmentResource::form()), but every
            // write path must be unable to persist it blank, mirroring
            // Lead's Validated-notes guard. CallRoutingService::
            // createAppointment() never sets it (the exact time often
            // isn't known yet when a call sets one up), so that one
            // specific insert is exempt.
            //
            // The other two conditions are deliberately separate — an
            // earlier version used isDirty('appointment_at') to gate both
            // the insert and update cases, but isDirty() only tracks keys
            // actually passed to create(); a manual create() that simply
            // omits appointment_at (rather than passing null) was never
            // "dirty" and slipped the guard entirely. So: on insert, check
            // the value directly; on update, still gate on isDirty() so
            // row actions that update unrelated fields (Reassign, Mark
            // Lost) aren't blocked by a pre-existing blank value they never
            // touched.
            $isExemptAutoRoutedInsert = ! $appointment->exists && $appointment->call_record_id !== null;
            $isUntouchedOnUpdate = $appointment->exists && ! $appointment->isDirty('appointment_at');

            if (! $isExemptAutoRoutedInsert && ! $isUntouchedOnUpdate && blank($appointment->appointment_at)) {
                throw new \LogicException('An Appointment cannot be saved without an Appointment At date/time.');
            }
        });
    }

    /**
     * withoutGlobalScope(SoftDeletingScope) so a soft-deleted Prospect's
     * company name still resolves here instead of silently going blank
     * (Change Request Section 7).
     */
    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class)->withoutGlobalScope(SoftDeletingScope::class);
    }

    public function callRecord(): BelongsTo
    {
        return $this->belongsTo(CallRecord::class);
    }

    public function assignedEmployee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Marks the Appointment Lost, capturing which stage it was sitting in at
     * the moment of loss, without touching its normal `stage` field or stage
     * history — mirrors Lead::markLost().
     */
    public function markLost(string $reason): void
    {
        $this->forceFill([
            'is_lost' => true,
            'lost_at_stage' => $this->stage,
            'lost_reason' => $reason,
            'lost_at' => Date::now(),
        ])->save();
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isAdmin() ? $query : $query->where('assigned_to', $user->id);
    }

    /**
     * @param  Builder<Appointment>  $query
     */
    public function scopeLost(Builder $query): Builder
    {
        return $query->where('is_lost', true);
    }
}
