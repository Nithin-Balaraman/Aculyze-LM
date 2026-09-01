<?php

namespace App\Models;

use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\EnforcesSameOrganizationRelations;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;

class Lead extends Model
{
    use BelongsToOrganization, EnforcesSameOrganizationRelations, HasFactory;

    protected $fillable = [
        'prospect_id',
        'call_record_id',
        'assigned_to',
        'created_by',
        'stage',
        'status',
        'temperature',
        'requirement_details',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'stage' => LeadStage::class,
            'status' => LeadStatus::class,
            'temperature' => LeadTemperature::class,
            'stage_changed_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'is_lost' => 'boolean',
            'lost_at_stage' => LeadStage::class,
            'lost_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new OrganizationScope);

        // Only real stage movement resets the stale clock — editing notes
        // must not make a 25-day-old Lead look freshly moved (AGENTS.md
        // section 22).
        static::saving(function (self $lead) {
            // Phase 3: legacy-creation compatibility fallback ONLY — same
            // shape as Appointment::booted() — a caller CREATING a new Lead
            // with `stage` but no explicit `status` gets one derived via
            // the single approved mapping (LeadStatus::fromLegacyStage()).
            // Create-only (never on update, where status is always already
            // loaded from the DB by normal Eloquent usage). Never overwrites
            // an explicit `status`, and an explicit `status` may legitimately
            // differ from what `stage` alone implies — normalized status is
            // authoritative, legacy stage is compatibility-only.
            if (! $lead->exists && $lead->status === null && $lead->stage !== null) {
                $lead->status = LeadStatus::fromLegacyStage($lead->stage);
            }

            if ($lead->isDirty('stage') || ! $lead->exists) {
                $lead->stage_changed_at = Date::now();
            }

            // Phase 2: the normalized LeadStatus column gets its own
            // independent changed-at clock — legacy `stage` and the new
            // `status` are two separate, permanently distinct columns
            // (see App\Enums\LeadStatus's docblock), never conflated.
            if ($lead->isDirty('status') || ! $lead->exists) {
                $lead->status_changed_at = Date::now();
            }

            // Validated Lead / Create Proposal batch: the Filament form
            // already blocks this interactively (see LeadResource::form()),
            // but every write path — including ones that bypass the visible
            // form — must be unable to persist a Lead ready for Proposal
            // without Notes/Remarks. Phase 3: migrated to key primarily on
            // normalized `status === ProposalRequired` — the exact
            // normalized equivalent of legacy Validated (see LeadStatus's
            // own label, and the same term WorkflowTransitionService already
            // uses for AppointmentOutcome/DemoNextAction's identical "ready
            // for Proposal" signal) — so this guard keeps applying to a Lead
            // that reaches ProposalRequired via the new workflow, whose
            // legacy `stage` is deliberately left frozen. Also still fires
            // for the legacy `stage === Validated` case directly:
            // LeadStatus::fromLegacyStage() deliberately does NOT map
            // Validated to ProposalRequired (it maps to the broader
            // RequirementConfirmed — ProposalRequired is only ever reached
            // via an explicit business decision), so a legacy-style direct
            // Validated creation must keep requiring notes on its own terms,
            // exactly reproducing the original stage-driven behavior.
            if (
                ($lead->status === LeadStatus::ProposalRequired || $lead->stage === LeadStage::Validated)
                && ! $lead->hasMeaningfulNotes()
            ) {
                throw new \LogicException('A Lead cannot be saved as ready for Proposal (status: Proposal Required) without Notes/Remarks.');
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
     * Phase 2: one Lead hasMany Demos — no unique constraint, since both
     * an explicit Reschedule and a completed Demo's "Another Demo
     * Required"/"Schedule Another Demo" outcome legitimately create
     * additional Demo rows against this same Lead (see App\Models\Demo's
     * own docblock). The same Lead persists throughout; a new Demo never
     * implies a new Lead.
     */
    public function demos(): HasMany
    {
        return $this->hasMany(Demo::class);
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
     * A Lead is stale once it has sat in the same active status for 30+
     * days without moving. Lost Leads are a closed outcome and never count
     * as stale. Phase 3: migrated from legacy `stage`/`stage_changed_at` to
     * normalized `status`/`status_changed_at` (LeadStatus::
     * isTerminalForStaleness()) — a Lead progressing through the new
     * workflow must not be falsely reported stale merely because its
     * legacy `stage` never advances (see AGENTS.md sections 22-23).
     */
    public function isStale(): bool
    {
        if ($this->is_lost || ($this->status?->isTerminalForStaleness() ?? true) || $this->status_changed_at === null) {
            return false;
        }

        return $this->status_changed_at->lte(
            Date::now()->subDays((int) config('aculyze.lead_stale_after_days'))
        );
    }

    /**
     * Senior Managers see every Lead in their organization; Managers see
     * their own + their direct reports'; Employees see only their own.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return \App\Support\Authorization\HierarchyVisibility::scopeFor($query, $user, 'assigned_to');
    }

    /**
     * Phase 3: migrated from legacy `stage`/`stage_changed_at` to
     * normalized `status`/`status_changed_at` (LeadStatus::
     * isTerminalForStaleness() — only NoCurrentProgression is terminal;
     * every other status keeps being monitored) — see isStale() above for
     * the same reasoning.
     *
     * @param  Builder<Lead>  $query
     */
    public function scopeStale(Builder $query): Builder
    {
        $threshold = Date::now()->subDays((int) config('aculyze.lead_stale_after_days'));

        return $query
            ->where('is_lost', false)
            ->whereNotIn('status', array_map(
                fn (LeadStatus $status) => $status->value,
                array_filter(LeadStatus::cases(), fn (LeadStatus $status) => $status->isTerminalForStaleness())
            ))
            ->whereNotNull('status_changed_at')
            ->where('status_changed_at', '<=', $threshold);
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

    /** Inherits organization_id from the Prospect this Lead is against. */
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
            'prospect_id' => ['prospects', 'Prospect'],
            'call_record_id' => ['call_records', 'Call Record'],
            'assigned_to' => ['users', 'assigned User'],
            'created_by' => ['users', 'creating User'],
        ];
    }
}
