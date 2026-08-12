<?php

namespace App\Models;

use App\Enums\CallOutcome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletingScope;

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

    /**
     * withoutGlobalScope(SoftDeletingScope) so a soft-deleted Prospect's
     * company name still resolves in every module's history instead of
     * silently going blank (Change Request Section 7).
     */
    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class)->withoutGlobalScope(SoftDeletingScope::class);
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
     * A Call Record blocks deletion once routing has created a downstream
     * Follow-Up/Appointment/Lead from it (Change Request Section 8) — those
     * are real history that must not vanish just because the originating
     * call is deleted.
     *
     * @return array<string, int>
     */
    public function deletionBlockers(): array
    {
        return [
            'Follow-Up' => (int) $this->followUp()->exists(),
            'Appointment' => (int) $this->appointment()->exists(),
            'Lead' => (int) $this->lead()->exists(),
        ];
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
