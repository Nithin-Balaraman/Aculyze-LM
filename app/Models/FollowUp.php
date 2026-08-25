<?php

namespace App\Models;

use App\Enums\ContactMode;
use App\Enums\FollowUpStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class FollowUp extends Model
{
    use HasFactory;

    protected $fillable = [
        'prospect_id',
        'call_record_id',
        'user_id',
        'follow_up_at',
        'next_follow_up_at',
        'contact_mode',
        'reason',
        'notes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => FollowUpStatus::class,
            'follow_up_at' => 'datetime',
            'next_follow_up_at' => 'datetime',
            'contact_mode' => ContactMode::class,
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
     * no specific callback time) — so that one specific insert is exempt.
     *
     * The other two conditions are deliberately separate — an earlier
     * version used isDirty('follow_up_at') to gate both the insert and
     * update cases, but isDirty() only tracks keys actually passed to
     * create(); a manual create() that simply omits follow_up_at (rather
     * than passing null) was never "dirty" and slipped the guard entirely.
     * So: on insert, check the value directly; on update, still gate on
     * isDirty() so row actions that update unrelated fields (Completed/
     * Close) aren't blocked by a pre-existing blank value they never
     * touched.
     */
    protected static function booted(): void
    {
        static::saving(function (self $followUp) {
            if (blank($followUp->reason)) {
                throw new \LogicException('A Follow-Up cannot be saved without a Reason.');
            }

            $isExemptAutoRoutedInsert = ! $followUp->exists && $followUp->call_record_id !== null;
            $isUntouchedOnUpdate = $followUp->exists && ! $followUp->isDirty('follow_up_at');

            if (! $isExemptAutoRoutedInsert && ! $isUntouchedOnUpdate && blank($followUp->follow_up_at)) {
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

    /**
     * The Call Record this Follow-Up's own "Completed" action created
     * (the reverse of CallRecord::generatedByFollowUp()) — exists only
     * once the Follow-Up has actually been completed.
     */
    public function generatedCallRecord(): HasOne
    {
        return $this->hasOne(CallRecord::class, 'follow_up_id');
    }

    public function responsibleEmployee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isAdmin() ? $query : $query->where('user_id', $user->id);
    }

    /**
     * A completed Follow-Up's generated Call Record (call_records.
     * follow_up_id) is a plain RESTRICT foreign key like everything else
     * in this schema — deleting a completed Follow-Up would otherwise fail
     * as a raw, uncaught 500 rather than the friendly DeletionGuard
     * message every other RESTRICT relationship gets.
     *
     * @return array<string, int>
     */
    public function deletionBlockers(): array
    {
        return [
            'Call Record' => (int) $this->generatedCallRecord()->exists(),
        ];
    }

    /**
     * Deletion Cascade fix: the generated Call Record (see
     * generatedCallRecord() above) has zero visibility anywhere else in
     * the app — Call Record history, KPIs, Pipeline Pulse, employee
     * deletion counts (see CallRecord::scopeDirectlyLogged()). Treating it
     * as a real blocking dependent the way DeletionGuard treats everything
     * else would force deleting this Follow-Up to first hunt down and
     * delete a record the user can never actually see. If it has no
     * downstream dependents of its own (CallRecord::deletionBlockers() —
     * i.e. it never routed to a Lead/Appointment/new Follow-Up), it's
     * purely an invisible byproduct and gets deleted along with this
     * Follow-Up. If it DID trigger downstream routing, that's real
     * history — this intentionally leaves it alone, so
     * deletionBlockers() above still reports it and DeletionGuard still
     * blocks the delete exactly as before.
     *
     * Intended to run in a DeleteAction's ->before() hook, ahead of
     * DeletionGuard::guardRecord() — see FollowUpResource::table() and
     * EditFollowUp::getHeaderActions().
     */
    public function deleteHarmlessGeneratedCallRecord(): void
    {
        $callRecord = $this->generatedCallRecord;

        if ($callRecord && array_filter($callRecord->deletionBlockers()) === []) {
            $callRecord->delete();
        }
    }
}
