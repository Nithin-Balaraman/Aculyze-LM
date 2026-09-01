<?php

namespace App\Models;

use App\Enums\AppointmentOutcome;
use App\Enums\AppointmentStage;
use App\Enums\AppointmentStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\EnforcesSameOrganizationRelations;
use App\Models\Concerns\GuardsScheduleAgainstDirectEdit;
use App\Models\Concerns\ValidatesOriginLineage;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;

/**
 * Phase 2: `status` (App\Enums\AppointmentStatus, lifecycle) and `outcome`
 * (App\Enums\AppointmentOutcome, business result) are additive alongside
 * the legacy `stage` column (App\Enums\AppointmentStage) — kept
 * permanently, untouched, read-only historical/compatibility data. New
 * code reads/writes `status`/`outcome`; see
 * App\Console\Commands\BackfillLeadAppointmentStatus for the exact
 * conservative legacy-to-status mapping.
 *
 * Two distinct linkage concepts, deliberately never merged:
 * - `rescheduled_from_id` = this Appointment REPLACES the same not-yet-
 *   conducted Appointment because its schedule changed
 *   (App\Services\RescheduleService).
 * - `origin_type`/`origin_id` = this Appointment was created as the next
 *   business action from a prior activity (e.g. a completed Appointment
 *   whose outcome was "Another Appointment Required" — see
 *   App\Services\WorkflowTransitionService). Only
 *   AppointmentOutcome::RequirementIdentified ever creates/moves to a
 *   Lead; no other outcome does.
 */
class Appointment extends Model implements \App\Models\Concerns\Reschedulable
{
    use BelongsToOrganization, EnforcesSameOrganizationRelations, GuardsScheduleAgainstDirectEdit, HasFactory, ValidatesOriginLineage;

    protected $fillable = [
        'prospect_id',
        'call_record_id',
        'assigned_to',
        'created_by',
        'appointment_at',
        'stage',
        'status',
        'outcome',
        'meeting_notes',
        'outcome_notes',
    ];

    protected function casts(): array
    {
        return [
            'stage' => AppointmentStage::class,
            'status' => AppointmentStatus::class,
            'outcome' => AppointmentOutcome::class,
            'appointment_at' => 'datetime',
            'stage_changed_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'is_lost' => 'boolean',
            'lost_at_stage' => AppointmentStage::class,
            'lost_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::saving(function (self $appointment) {
            // Phase 3: legacy-creation compatibility fallback ONLY — if a
            // caller CREATES a new Appointment supplying `stage` without an
            // explicit `status` (older fixtures, legacy-compatible direct
            // creation, forms not yet fully normalized), derive `status`
            // from it via the single approved mapping
            // (AppointmentStatus::fromLegacyStage()) so it is never left at
            // a blind DB default. Insert-only (never on update) — an
            // existing record's `status` is always already loaded from the
            // DB by normal Eloquent usage, so a null in-memory value on an
            // update means the caller is deliberately touching unrelated
            // fields, not that status needs deriving. This never overwrites
            // an explicitly-supplied `status` — ordinary Phase 3 business
            // workflow (WorkflowTransitionService) always writes `status`
            // itself and must never depend on this fallback. An explicit
            // `status` may legitimately differ from what `stage` alone
            // would imply (e.g. a workflow-completed Appointment whose
            // `stage` is deliberately left frozen) — that is never rejected
            // or "corrected" here.
            if (! $appointment->exists && $appointment->status === null && $appointment->stage !== null) {
                $appointment->status = AppointmentStatus::fromLegacyStage($appointment->stage);
            }

            // stage_changed_at must only move when the stage itself
            // changes — never on unrelated edits like notes (AGENTS.md
            // section 19).
            if ($appointment->isDirty('stage') || ! $appointment->exists) {
                $appointment->stage_changed_at = Date::now();
            }

            // Phase 2: the normalized AppointmentStatus column gets its
            // own independent changed-at clock — legacy `stage` and the
            // new `status` are two separate, permanently distinct
            // columns, never conflated.
            if ($appointment->isDirty('status') || ! $appointment->exists) {
                $appointment->status_changed_at = Date::now();
            }

            // Appointment At is mandatory (Change Request: Mandatory
            // Fields batch) — the Filament form already blocks this
            // interactively (see AppointmentResource::form()), but every
            // write path must be unable to persist it blank, mirroring
            // Lead's Validated-notes guard. CallRoutingService::
            // createAppointment() never sets it (the exact time often
            // isn't known yet when a call sets one up), so that one
            // specific insert is exempt.
            //
            // The other two conditions are deliberately separate — an
            // earlier version used isDirty('appointment_at') to gate both
            // the insert and update cases, but isDirty() only tracks keys
            // actually passed to create(); a manual create() that simply
            // omits appointment_at (rather than passing null) was never
            // "dirty" and slipped the guard entirely. So: on insert, check
            // the value directly; on update, still gate on isDirty() so
            // row actions that update unrelated fields (Reassign, Mark
            // Lost) aren't blocked by a pre-existing blank value they never
            // touched.
            $isExemptAutoRoutedInsert = ! $appointment->exists && $appointment->call_record_id !== null;
            $isUntouchedOnUpdate = $appointment->exists && ! $appointment->isDirty('appointment_at');

            if (! $isExemptAutoRoutedInsert && ! $isUntouchedOnUpdate && blank($appointment->appointment_at)) {
                throw new \LogicException('An Appointment cannot be saved without an Appointment At date/time.');
            }

            // Notes/Remarks batch: a real business outcome being recorded
            // must have Outcome Notes documenting it. Phase 3: migrated
            // from keying purely on legacy `stage`->isTerminal() to the
            // normalized `status`, but `AppointmentStatus::Completed` is a
            // many-to-one target of `fromLegacyStage()` (VisitConducted,
            // DiscussionCompleted, Succeeded, AND NotSucceeded all collapse
            // to it) — broader than the legacy "terminal" concept (only
            // Succeeded/NotSucceeded), and AppointmentOutcome's own
            // docblock already establishes the invariant that a legacy row
            // reaching Completed with no real outcome captured is left with
            // `outcome = NULL` (see BackfillLeadAppointmentStatus). So this
            // guard fires only when EITHER a real outcome is actually being
            // recorded (outcome not null — true for every
            // WorkflowTransitionService completion, which always sets one),
            // OR the underlying legacy stage is itself genuinely terminal
            // (Succeeded/NotSucceeded) — reproducing the exact original
            // legacy-stage behavior for stage-driven writes, without
            // over-triggering for a merely Completed-via-fallback
            // non-terminal legacy stage (e.g. VisitConducted).
            $reachedCompletedWithRealOutcome = $appointment->status === AppointmentStatus::Completed
                && ($appointment->outcome !== null || ($appointment->stage !== null && $appointment->stage->isTerminal()));

            if (
                ($appointment->isDirty('status') || ! $appointment->exists)
                && $reachedCompletedWithRealOutcome
                && ! $appointment->hasMeaningfulOutcomeNotes()
            ) {
                throw new \LogicException('An Appointment cannot be saved as Completed without Outcome Notes.');
            }
        });
    }

    /**
     * Whitespace-only Outcome Notes must not count as present — mirrors
     * Lead::hasMeaningfulNotes().
     */
    public function hasMeaningfulOutcomeNotes(): bool
    {
        return filled($this->outcome_notes);
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

    /** The prior, not-yet-conducted Appointment this one replaced via an explicit Reschedule — never a completed one. */
    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rescheduled_from_id');
    }

    /** The Appointment that replaced this one via an explicit Reschedule, if any — computed, not a second physical column. */
    public function replacedBy(): HasOne
    {
        return $this->hasOne(self::class, 'rescheduled_from_id');
    }

    /** Which prior workflow activity caused this Appointment to be created as the next business action — lineage, not reschedule linkage. */
    public function origin(): MorphTo
    {
        return $this->morphTo();
    }

    public function scheduledAtColumn(): string
    {
        return 'appointment_at';
    }

    public function statusEnumClass(): string
    {
        return AppointmentStatus::class;
    }

    public function activeStatusValue(): \BackedEnum
    {
        return AppointmentStatus::Scheduled;
    }

    public function rescheduledStatusValue(): \BackedEnum
    {
        return AppointmentStatus::Rescheduled;
    }

    public function replacementAttributesForReschedule(): array
    {
        return [
            'prospect_id' => $this->prospect_id,
            'assigned_to' => $this->assigned_to,
            'created_by' => $this->created_by,
            'stage' => AppointmentStage::AppointmentMade,
        ];
    }

    public function isOverdue(): bool
    {
        return $this->status === AppointmentStatus::Scheduled
            && $this->appointment_at !== null
            && $this->appointment_at->isPast()
            && ! $this->appointment_at->isToday();
    }

    public function isDueToday(): bool
    {
        return $this->status === AppointmentStatus::Scheduled
            && $this->appointment_at !== null
            && $this->appointment_at->isToday();
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
     * Marks the Appointment Lost, capturing which stage it was sitting in at
     * the moment of loss, without touching its normal `stage` field or stage
     * history — mirrors Lead::markLost().
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
     * Senior Managers see every Appointment in their organization; Managers
     * see their own + their direct reports'; Employees see only their own.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return \App\Support\Authorization\HierarchyVisibility::scopeFor($query, $user, 'assigned_to');
    }

    /**
     * @param  Builder<Appointment>  $query
     */
    public function scopeLost(Builder $query): Builder
    {
        return $query->where('is_lost', true);
    }

    /**
     * Excludes records whose normalized `status` (Rescheduled/Completed/
     * Cancelled) marks them historical under the Phase 2 model — the
     * legacy `stage` alone (still the Pipeline Board's/List page's
     * grouping key) does not reflect a Reschedule or a
     * WorkflowTransitionService outcome, since neither ever changes it.
     * Without this, a Rescheduled/repeat-activity-Completed Appointment
     * would keep appearing as if still active under whatever stage it
     * happened to be sitting in. NULL/not-yet-backfilled `status` and
     * `Scheduled` both pass through unaffected — this only ever removes a
     * record that the new model explicitly marks historical.
     *
     * @param  Builder<Appointment>  $query
     */
    public function scopeExcludingHistoricalStatus(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->whereNull('status')->orWhereNotIn('status', [
                AppointmentStatus::Rescheduled->value,
                AppointmentStatus::Completed->value,
                AppointmentStatus::Cancelled->value,
            ]);
        });
    }

    /**
     * The inverse of scopeExcludingHistoricalStatus() — records the
     * Phase 2 status model considers historical, for History-style views.
     *
     * @param  Builder<Appointment>  $query
     */
    public function scopeHistoricalStatus(Builder $query): Builder
    {
        return $query->whereIn('status', [
            AppointmentStatus::Rescheduled->value,
            AppointmentStatus::Completed->value,
            AppointmentStatus::Cancelled->value,
        ]);
    }

    /** Inherits organization_id from the Prospect this Appointment is against. */
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
            'rescheduled_from_id' => ['appointments', 'original Appointment'],
        ];
    }
}
