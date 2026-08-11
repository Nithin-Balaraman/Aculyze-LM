<?php

namespace App\Models;

use App\Enums\CallOutcome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The Call Record IS the Activity Log (AGENTS.md section 12/51) — there is
 * no separate Activity Log entity. Every call made against a Prospect, no
 * matter the outcome, must create one of these.
 */
class CallRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'prospect_id',
        'user_id',
        'called_at',
        'outcome',
        'notes',
        'callback_required',
        'callback_at',
        'contact_person_spoken_to',
        'phone_called',
    ];

    protected function casts(): array
    {
        return [
            'outcome' => CallOutcome::class,
            'called_at' => 'datetime',
            'callback_at' => 'datetime',
            'callback_required' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
    }

    /** The employee who actually made this call. Never changes on reassignment. */
    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function followUp(): HasOne
    {
        return $this->hasOne(FollowUp::class);
    }

    public function appointment(): HasOne
    {
        return $this->hasOne(Appointment::class);
    }

    public function lead(): HasOne
    {
        return $this->hasOne(Lead::class);
    }

    /**
     * Admins see every Call Record; Employees only see calls they personally
     * made (ownership here is "who made the call", not the Prospect's
     * current assignee).
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isAdmin() ? $query : $query->where('user_id', $user->id);
    }
}
