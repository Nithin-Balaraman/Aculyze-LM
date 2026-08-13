<?php

namespace App\Models;

use App\Enums\ProposalOutcome;
use App\Enums\ProposalStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Date;

class Proposal extends Model
{
    use HasFactory;

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
    ];

    protected function casts(): array
    {
        return [
            'stage' => ProposalStage::class,
            'outcome' => ProposalOutcome::class,
            'value' => 'decimal:2',
            'sent_at' => 'date',
            'stage_changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Only real stage/outcome movement resets the stale clock — editing
        // notes or the proposal value must not reset it (AGENTS.md
        // sections 27-28, mirroring the Lead stage-timing rule).
        static::saving(function (self $proposal) {
            if ($proposal->isDirty('stage') || $proposal->isDirty('outcome') || ! $proposal->exists) {
                $proposal->stage_changed_at = Date::now();
            }
        });
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

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isAdmin() ? $query : $query->where('assigned_to', $user->id);
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
}
