<?php

namespace App\Models;

use App\Enums\FollowUpStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class FollowUp extends Model
{
    use HasFactory;

    protected $fillable = [
        'prospect_id',
        'call_record_id',
        'user_id',
        'follow_up_at',
        'reason',
        'notes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => FollowUpStatus::class,
            'follow_up_at' => 'datetime',
        ];
    }

    /**
     * Reason and Follow Up At are mandatory (Change Request: Mandatory
     * Fields batch) — the Filament form already blocks both interactively
     * (see FollowUpResource::form()), but every write path must be unable
     * to persist either blank, mirroring Lead's Validated-notes guard.
     *
     * Reason is always populated by CallRoutingService::createFollowUp()
     * (set to the originating outcome's label), so it's checked
     * unconditionally on every save. Follow Up At is NOT always known at
     * auto-creation time (e.g. a No Answer call creates a Follow-Up with
     * no specific callback time) — so that one specific write is exempt,
     * but any later save that actually sets Follow Up At to blank (via the
     * form or otherwise) is still rejected. Row actions that update other
     * fields (Completed/Close) never touch follow_up_at, so isDirty()
     * keeps them unaffected by a pre-existing blank value.
     */
    protected static function booted(): void
    {
        static::saving(function (self $followUp) {
            if (blank($followUp->reason)) {
                throw new \LogicException('A Follow-Up cannot be saved without a Reason.');
            }

            $isInitialAutoRoutedInsert = ! $followUp->exists && $followUp->call_record_id !== null;

            if (! $isInitialAutoRoutedInsert && $followUp->isDirty('follow_up_at') && blank($followUp->follow_up_at)) {
                throw new \LogicException('A Follow-Up cannot be saved without a Follow Up At date/time.');
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

    public function responsibleEmployee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isAdmin() ? $query : $query->where('user_id', $user->id);
    }
}
