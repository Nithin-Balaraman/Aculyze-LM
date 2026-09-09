<?php

namespace App\Models;

use App\Enums\ProposalOutcome;
use App\Enums\ProposalStage;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\EnforcesSameOrganizationRelations;
use App\Models\Scopes\OrganizationScope;
use App\Support\Authorization\HierarchyVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

class Proposal extends Model
{
    use BelongsToOrganization, EnforcesSameOrganizationRelations, HasFactory;

    protected $fillable = [
        'lead_id',
        'prospect_id',
        'assigned_to',
        'created_by',
        'stage',
        'outcome',
        'value',
        'sent_at',
        'notes',
        'attachment_paths',
        'attachment_names',
    ];

    protected function casts(): array
    {
        return [
            'stage' => ProposalStage::class,
            'outcome' => ProposalOutcome::class,
            'value' => 'decimal:2',
            'sent_at' => 'date',
            'stage_changed_at' => 'datetime',
            'last_client_activity_at' => 'datetime',
            'attachment_paths' => 'array',
            'attachment_names' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new OrganizationScope);

        // Only a genuine STAGE change resets the stale clock (locked
        // Decision 18, Phase 4A-3.1 correction) — `outcome` changing by
        // itself no longer does, even on its very first change (e.g. a
        // More Time client response setting outcome=Hold while stage stays
        // exactly what it was). This was verified safe against every
        // existing runtime writer of `outcome` before being narrowed:
        // PipelineBoard::dropProposal() and resolveCrossDropSource()'s
        // proposal branch both always set `stage` in the same write
        // whenever they set `outcome` (dropProposal() even bails out
        // early if the dragged stage wouldn't change), so no existing path
        // relied on outcome-only dirtying to reset this timestamp.
        // Meaningful client-activity staleness (More Time, Other -> Create
        // Follow-Up) is tracked separately via `last_client_activity_at`,
        // written only by the not-yet-implemented
        // ProposalClientResponseService (4A-3.4) — see Proposal::isStale().
        static::saving(function (self $proposal) {
            if ($proposal->isDirty('stage') || ! $proposal->exists) {
                $proposal->stage_changed_at = Date::now();
            }

            // Notes/Remarks batch: a final outcome (Won or Lost) must have
            // Notes documenting it — the Filament form already blocks this
            // interactively (see ProposalResource::form()), but every write
            // path must be unable to persist one without Notes, mirroring
            // Lead's Validated-requires-notes guard. Hold and "still in
            // progress" (null) are unaffected — only a genuine final
            // decision requires this.
            if (
                in_array($proposal->outcome, [ProposalOutcome::Won, ProposalOutcome::Lost], true)
                && ! $proposal->hasMeaningfulNotes()
            ) {
                throw new \LogicException('A Proposal cannot be saved with outcome Won or Lost without Notes.');
            }
        });
    }

    /**
     * Whitespace-only Notes must not count as present — mirrors
     * Lead::hasMeaningfulNotes().
     */
    public function hasMeaningfulNotes(): bool
    {
        return filled($this->notes);
    }

    public function hasAttachments(): bool
    {
        return filled($this->attachment_paths);
    }

    /**
     * Every attached file as [stored path => display name], in upload
     * order. attachment_names is keyed by stored path (the same shape
     * Filament's FileUpload field itself writes via storeFileNamesIn() —
     * see BaseFileUpload::storeFileName()); falls back to the stored
     * path's own basename for an attachment that somehow has no recorded
     * name (shouldn't happen for anything uploaded through the field
     * itself, but keeps this honest rather than emitting a blank label).
     *
     * @return array<string, string>
     */
    public function attachments(): array
    {
        $names = $this->attachment_names ?? [];

        return collect($this->attachment_paths ?? [])
            ->mapWithKeys(fn (string $path) => [$path => $names[$path] ?? basename($path)])
            ->all();
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
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

    public function assignedEmployee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Phase 4A-1: every immutable commercial-document snapshot ever taken
     * of this Proposal (Master BA Specification section 3.2) — V1, V2,
     * V3... never mutated once created, only ever appended to.
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ProposalVersion::class)->orderBy('version_number');
    }

    /**
     * Phase 4A-2.5 bug fix: the same history, newest Version first — the
     * explicit order the commercial Version History reads in. A dedicated
     * relation (rather than an ad-hoc ->state() closure on the infolist)
     * specifically because Filament resolves every RepeatableEntry child
     * by data_get()-ing the entry's absolute state path against the
     * infolist's own record: the path has to be a real, resolvable
     * relation name or every plain column renders blank.
     */
    public function versionsNewestFirst(): HasMany
    {
        return $this->hasMany(ProposalVersion::class)->orderByDesc('version_number');
    }

    /** The exact ProposalVersion the team is currently working with commercially — moves atomically when a new Draft revision is created (section 7). */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ProposalVersion::class, 'current_version_id');
    }

    /** Set only when an exact sent/non-superseded version is accepted (section 7) — the authoritative source for Won reporting and billing handoff. */
    public function winningVersion(): BelongsTo
    {
        return $this->belongsTo(ProposalVersion::class, 'winning_version_id');
    }

    /**
     * Phase 4A-3.1 (schema/model only — populated starting in 4A-3.3/4A-3.4).
     * Every send/client-response/billing-handoff row against ANY Version of
     * this Proposal — denormalized directly onto proposal_id precisely so
     * these don't require joining through versions() for every
     * Proposal-scoped query (same reasoning already documented for
     * prospect_id's own denormalization above).
     */
    public function sends(): HasMany
    {
        return $this->hasMany(ProposalSend::class);
    }

    public function clientResponses(): HasMany
    {
        return $this->hasMany(ProposalClientResponse::class);
    }

    /**
     * Deliberately hasMany, not hasOne: the exactly-once backstop
     * (`UNIQUE(accepted_response_id)` on proposal_billing_handoffs) is
     * scoped to one handoff per Accepted EVENT, not one per Proposal ever —
     * a future Reopen may legitimately produce a second handoff for the
     * same Proposal (locked Decision 15).
     */
    public function billingHandoffs(): HasMany
    {
        return $this->hasMany(ProposalBillingHandoff::class);
    }

    /**
     * A Proposal is stale once it has sat without stage movement OR
     * meaningful client activity for 20+ days, unless it has a closed
     * outcome (Won/Lost always closed; Hold is configurable — see
     * ProposalOutcome::isTerminalForStaleness()).
     *
     * `last_client_activity_at` (locked Decision 18, Phase 4A-3.1
     * correction) is a service-owned cache written only by the
     * not-yet-implemented ProposalClientResponseService (4A-3.4), only for
     * More Time and Other -> Create Follow-Up responses — never for Other
     * -> Await Further Contact, never by any other write path. It can only
     * ever push the effective reference date LATER (fresher); a Proposal
     * with no `stage_changed_at` at all is never stale regardless of
     * `last_client_activity_at`, preserving the exact existing semantic.
     */
    public function isStale(): bool
    {
        if ($this->outcome?->isTerminalForStaleness() || $this->stage_changed_at === null) {
            return false;
        }

        $reference = $this->last_client_activity_at !== null && $this->last_client_activity_at->gt($this->stage_changed_at)
            ? $this->last_client_activity_at
            : $this->stage_changed_at;

        return $reference->lte(
            Date::now()->subDays((int) config('aculyze.proposal_stale_after_days'))
        );
    }

    /**
     * Senior Managers see every Proposal in their organization; Managers see
     * their own + their direct reports'; Employees see only their own.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return HierarchyVisibility::scopeFor($query, $user, 'assigned_to');
    }

    /**
     * @param  Builder<Proposal>  $query
     */
    public function scopeStale(Builder $query): Builder
    {
        $threshold = Date::now()->subDays((int) config('aculyze.proposal_stale_after_days'));

        $terminalOutcomes = array_map(
            fn (ProposalOutcome $outcome) => $outcome->value,
            array_filter(ProposalOutcome::cases(), fn (ProposalOutcome $outcome) => $outcome->isTerminalForStaleness())
        );

        // NULL-safe equivalent of isStale()'s "later of stage_changed_at
        // and last_client_activity_at" reference date (locked Decision 18,
        // Phase 4A-3.1 correction). Raw GREATEST() in MariaDB/MySQL returns
        // NULL the moment ANY argument is NULL — unlike PostgreSQL, it does
        // NOT ignore nulls — so `last_client_activity_at` (NULL for most
        // Proposals) is COALESCEd against `stage_changed_at` before the
        // comparison, and the whole expression is only reached at all once
        // `stage_changed_at IS NOT NULL` has already been confirmed,
        // preserving "no stage_changed_at ⇒ never stale" exactly.
        return $query
            ->where(function (Builder $query) use ($terminalOutcomes) {
                $query->whereNull('outcome')->orWhereNotIn('outcome', $terminalOutcomes);
            })
            ->whereNotNull('stage_changed_at')
            ->whereRaw(
                'GREATEST(stage_changed_at, COALESCE(last_client_activity_at, stage_changed_at)) <= ?',
                [$threshold]
            );
    }

    /**
     * Audit fix pass 1, F1 (locked Decision D1): a Proposal blocks deletion
     * the moment it has ANY ProposalVersion. Commercial Versions are
     * permanent commercial/audit history — an Approved or Sent Version must
     * never disappear because someone clicked Delete on its parent Proposal
     * — and proposal_versions.proposal_id is RESTRICT precisely so the
     * database agrees. This is the friendly, reusable, server-side half of
     * that same rule: the blocker is evaluated before delete() is ever
     * called (App\Support\DeletionGuard), leaving the RESTRICT constraint as
     * a genuine last line of defence rather than the user-facing behaviour.
     *
     * A Proposal with zero Versions keeps the existing delete behaviour.
     *
     * @return array<string, int>
     */
    public function deletionBlockers(): array
    {
        return [
            'commercial Version(s)' => $this->versions()->count(),
        ];
    }

    /** Commercial Versions are never "reassigned or removed" — see App\Support\DeletionGuard::message(). */
    public function deletionBlockerAdvice(): string
    {
        return 'This Proposal has commercial version history and cannot be deleted — commercial Versions are permanent records and are never removed.';
    }

    /** Inherits organization_id from the Prospect this Proposal is against. */
    protected function inheritedOrganizationId(): ?int
    {
        if (! $this->prospect_id) {
            return null;
        }

        return DB::table('prospects')->where('id', $this->prospect_id)->value('organization_id');
    }

    /** @return array<string, array{0: string, 1: string}> */
    protected function organizationScopedRelations(): array
    {
        return [
            'lead_id' => ['leads', 'Lead'],
            'prospect_id' => ['prospects', 'Prospect'],
            'assigned_to' => ['users', 'assigned User'],
            'created_by' => ['users', 'creating User'],
            'current_version_id' => ['proposal_versions', 'current Version'],
            'winning_version_id' => ['proposal_versions', 'winning Version'],
        ];
    }
}
