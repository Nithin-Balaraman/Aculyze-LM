<?php

namespace App\Models;

use App\Enums\LeadStage;
use App\Enums\LeadTemperature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Date;

class Lead extends Model
{
    use HasFactory;

    protected $fillable = [
        'prospect_id',
        'call_record_id',
        'assigned_to',
        'created_by',
        'stage',
        'temperature',
        'requirement_details',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'stage' => LeadStage::class,
            'temperature' => LeadTemperature::class,
            'stage_changed_at' => 'datetime',
            'is_lost' => 'boolean',
            'lost_at_stage' => LeadStage::class,
            'lost_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Only real stage movement resets the stale clock — editing notes
        // must not make a 25-day-old Lead look freshly moved (AGENTS.md
        // section 22).
        static::saving(function (self $lead) {
            if ($lead->isDirty('stage') || ! $lead->exists) {
                $lead->stage_changed_at = Date::now();
            }

            // Validated Lead / Create Proposal batch: the Filament form
            // already blocks this interactively (see LeadResource::form()),
            // but every write path — including ones that bypass the visible
            // form — must be unable to persist a Validated Lead without
            // Notes/Remarks.
            if ($lead->stage === LeadStage::Validated && ! $lead->hasMeaningfulNotes()) {
                throw new \LogicException('A Lead cannot be saved as Validated without Notes/Remarks.');
            }

            // Mandatory Fields batch: Temperature is required — checked
            // unconditionally (unlike Follow Up At/Appointment At below in
            // FollowUp/Appointment) because it's always populated by every
            // existing write path already (the form defaults it and
            // requires it; CallRoutingService::createLead() always sets it
            // to Warm), so there's no legitimate blank case to exempt.
            if ($lead->temperature === null) {
                throw new \LogicException('A Lead cannot be saved without a Temperature.');
            }
        });
    }

    /**
     * Whitespace-only Notes must not count as present — filled() already
     * treats a whitespace-only string as blank (see Illuminate's blank()),
     * so this is the same "meaningful content" check used elsewhere in the
     * app (e.g. ImportProspects' company-name check) rather than a new one.
     */
    public function hasMeaningfulNotes(): bool
    {
        return filled($this->notes);
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

    public function proposal(): HasOne
    {
        return $this->hasOne(Proposal::class);
    }

    /**
     * Marks the Lead Lost, capturing which stage it was sitting in at the
     * moment of loss (for the "leads lost by stage" report/chart) without
     * touching its normal `stage` field or stage history — Lost is an
     * outcome applied on top of wherever the Lead currently is, per the
     * Change Request "Decision 2".
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

    /**
     * A Lead is stale once it has sat in the same active (non-terminal)
     * stage for 30+ days without moving. Lost Leads are a closed outcome
     * and never count as stale. See AGENTS.md sections 22-23.
     */
    public function isStale(): bool
    {
        if ($this->is_lost || $this->stage->isTerminal() || $this->stage_changed_at === null) {
            return false;
        }

        return $this->stage_changed_at->lte(
            Date::now()->subDays((int) config('aculyze.lead_stale_after_days'))
        );
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isAdmin() ? $query : $query->where('assigned_to', $user->id);
    }

    /**
     * @param  Builder<Lead>  $query
     */
    public function scopeStale(Builder $query): Builder
    {
        $threshold = Date::now()->subDays((int) config('aculyze.lead_stale_after_days'));

        return $query
            ->where('is_lost', false)
            ->whereNotIn('stage', array_map(
                fn (LeadStage $stage) => $stage->value,
                array_filter(LeadStage::cases(), fn (LeadStage $stage) => $stage->isTerminal())
            ))
            ->whereNotNull('stage_changed_at')
            ->where('stage_changed_at', '<=', $threshold);
    }

    /**
     * @param  Builder<Lead>  $query
     */
    public function scopeLost(Builder $query): Builder
    {
        return $query->where('is_lost', true);
    }

    /**
     * A Lead blocks deletion once it has a Proposal (Change Request Section
     * 9, folded into the same blocking-delete fix as Sections 5 & 8) — the
     * Proposal is real sales history that must not vanish with its Lead.
     *
     * @return array<string, int>
     */
    public function deletionBlockers(): array
    {
        return [
            'Proposal' => (int) $this->proposal()->exists(),
        ];
    }
}
