<?php

namespace App\Models;

use App\Enums\CallOutcome;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\EnforcesSameOrganizationRelations;
use App\Models\Scopes\OrganizationScope;
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
 *
 * organization_id (Phase 1) is inherited from the Prospect being called,
 * never guessed from whoever happens to be logged in — see
 * inheritedOrganizationId() below.
 */
class CallRecord extends Model
{
    use BelongsToOrganization, EnforcesSameOrganizationRelations, HasFactory;

    protected $fillable = [
        'prospect_id',
        'user_id',
        'called_at',
        'outcome',
        'notes',
        'follow_up_at',
        'appointment_at',
        'contact_person_spoken_to',
        'designation',
        'phone_called',
        'follow_up_id',
    ];

    protected function casts(): array
    {
        return [
            'outcome' => CallOutcome::class,
            'called_at' => 'datetime',
            'follow_up_at' => 'datetime',
            'appointment_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Notes are required for any outcome where a real conversation actually
     * happened (CallOutcome::requiresNotes() — every outcome except the
     * three "never connected" ones) — was previously scoped to just Others
     * (the catch-all with no defined routing). The Filament form already
     * blocks this interactively (see CallRecordResource::form()), but every
     * write path — including ones that bypass the visible form — must be
     * unable to persist such a Call Record without Notes. Mirrors Lead's
     * Validated-requires-notes guard.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::saving(function (self $callRecord) {
            if ($callRecord->outcome->requiresNotes() && ! $callRecord->hasMeaningfulNotes()) {
                throw new \LogicException("A Call Record cannot be saved with outcome {$callRecord->outcome->getLabel()} without Notes.");
            }
        });
    }

    /** @return array<string, array{0: string, 1: string}> */
    protected function organizationScopedRelations(): array
    {
        return [
            'prospect_id' => ['prospects', 'Prospect'],
            'user_id' => ['users', 'User'],
            'follow_up_id' => ['follow_ups', 'Follow-Up'],
        ];
    }

    /**
     * Whitespace-only Notes must not count as present — mirrors
     * Lead::hasMeaningfulNotes().
     */
    public function hasMeaningfulNotes(): bool
    {
        return filled($this->notes);
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

    /**
     * The Follow-Up whose "Completed" action generated THIS Call Record
     * (the reverse of FollowUp::generatedCallRecord()) — null for every
     * directly-logged call. Not to be confused with followUp() above,
     * which is the opposite direction: the Follow-Up THIS call spawned.
     */
    public function generatedByFollowUp(): BelongsTo
    {
        return $this->belongsTo(FollowUp::class, 'follow_up_id');
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
     * Senior Managers see every Call Record in their organization; Managers
     * see their own + their direct reports'; Employees only see calls they
     * personally made (ownership here is "who made the call", not the
     * Prospect's current assignee).
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return \App\Support\Authorization\HierarchyVisibility::scopeFor($query, $user, 'user_id');
    }

    /**
     * Inherits organization_id from the Prospect being called, never from
     * whoever happens to be logged in. Reads via the query builder rather
     * than the prospect() Eloquent relation deliberately — this avoids any
     * dependency on OrganizationScope/TenantContext already being resolvable
     * at the point a brand-new CallRecord is being created, and it is a
     * safe, trusted read of an already-set foreign key's own column, not a
     * tenant-boundary-crossing operation.
     */
    protected function inheritedOrganizationId(): ?int
    {
        if (! $this->prospect_id) {
            return null;
        }

        return \Illuminate\Support\Facades\DB::table('prospects')
            ->where('id', $this->prospect_id)
            ->value('organization_id');
    }
}
