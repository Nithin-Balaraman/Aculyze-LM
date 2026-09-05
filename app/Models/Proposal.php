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
            'attachment_paths' => 'array',
            'attachment_names' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new OrganizationScope);

        // Only real stage/outcome movement resets the stale clock — editing
        // notes or the proposal value must not reset it (AGENTS.md
        // sections 27-28, mirroring the Lead stage-timing rule).
        static::saving(function (self $proposal) {
            if ($proposal->isDirty('stage') || $proposal->isDirty('outcome') || ! $proposal->exists) {
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
     * A Proposal is stale once it has sat without stage/outcome movement for
     * 20+ days, unless it has a closed outcome (Won/Lost always closed;
     * Hold is configurable — see ProposalOutcome::isTerminalForStaleness()).
     */
    public function isStale(): bool
    {
        if ($this->outcome?->isTerminalForStaleness() || $this->stage_changed_at === null) {
            return false;
        }

        return $this->stage_changed_at->lte(
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

        return $query
            ->where(function (Builder $query) use ($terminalOutcomes) {
                $query->whereNull('outcome')->orWhereNotIn('outcome', $terminalOutcomes);
            })
            ->whereNotNull('stage_changed_at')
            ->where('stage_changed_at', '<=', $threshold);
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
