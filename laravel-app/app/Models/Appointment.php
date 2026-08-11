<?php

namespace App\Models;

use App\Enums\AppointmentStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        ];
    }

    protected static function booted(): void
    {
        // stage_changed_at must only move when the stage itself changes —
        // never on unrelated edits like notes (AGENTS.md section 19).
        static::saving(function (self $appointment) {
            if ($appointment->isDirty('stage') || ! $appointment->exists) {
                $appointment->stage_changed_at = Date::now();
            }
        });
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
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

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isAdmin() ? $query : $query->where('assigned_to', $user->id);
    }
}
