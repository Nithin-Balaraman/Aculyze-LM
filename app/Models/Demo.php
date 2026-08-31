<?php

namespace App\Models;

use App\Enums\DemoMode;
use App\Enums\DemoNextAction;
use App\Enums\DemoOutcome;
use App\Enums\DemoStatus;
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
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 domain/model foundation — no Filament Resource, no Pipeline
 * Board lane yet (Phase 3). One Lead hasMany Demos: no unique constraint
 * on lead_id, since both an explicit Reschedule and a completed Demo's
 * "Another Demo Required"/"Schedule Another Demo" outcome legitimately
 * create additional Demo rows against the same Lead — the same Lead
 * persists throughout, never duplicated.
 *
 * Never created directly by application code outside
 * App\Services\WorkflowTransitionService::transitionToDemo() — Demo is a
 * first-class workflow activity reached through a valid transition
 * (Follow-Up/Appointment/Lead/Proposal -> Demo), never fabricated merely
 * because a Lead enum value changes.
 *
 * Two distinct linkage concepts, deliberately never merged (see the
 * Phase 2 plan's "repeat activity vs reschedule" correction):
 * - `rescheduled_from_id` = this Demo REPLACES the same not-yet-conducted
 *   Demo because its schedule changed (App\Services\RescheduleService).
 * - `origin_type`/`origin_id` = this Demo was created as the next
 *   business action from a prior activity — a completed Demo whose
 *   outcome/next_action was "Schedule Another Demo", or a Follow-Up/
 *   Appointment/Lead/Proposal transitioning to Demo for the first time.
 */
class Demo extends Model implements \App\Models\Concerns\Reschedulable
{
    use BelongsToOrganization;
    use EnforcesSameOrganizationRelations;
    use GuardsScheduleAgainstDirectEdit;
    use HasFactory;
    use ValidatesOriginLineage;

    protected $fillable = [
        'prospect_id',
        'lead_id',
        'assigned_to',
        'created_by',
        'demo_at',
        'mode',
        'location',
        'meeting_link',
        'attendees',
        'product_service',
        'purpose',
        'feedback',
        'correction_comments',
        'notes',
        'status',
        'outcome',
        'next_action',
    ];

    protected function casts(): array
    {
        return [
            'demo_at' => 'datetime',
            'mode' => DemoMode::class,
            'status' => DemoStatus::class,
            'outcome' => DemoOutcome::class,
            'next_action' => DemoNextAction::class,
            'attendees' => 'array',
            'status_changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::saving(function (self $demo) {
            // Only real status movement resets the clock — editing notes
            // must never make a Demo look freshly moved (mirrors
            // Appointment::booted()/Lead::booted()).
            if ($demo->isDirty('status') || ! $demo->exists) {
                $demo->status_changed_at = Date::now();
            }

            if ($demo->mode === DemoMode::OnSite && blank($demo->location)) {
                throw new \LogicException('A Demo cannot be saved as On-site without a Location.');
            }

            if ($demo->mode === DemoMode::Online && blank($demo->meeting_link)) {
                throw new \LogicException('A Demo cannot be saved as Online without a Meeting Link.');
            }

            if ($demo->outcome === DemoOutcome::Other && blank($demo->notes)) {
                throw new \LogicException('A Demo cannot be saved with outcome Other without Notes.');
            }
        });
    }

    public function scheduledAtColumn(): string
    {
        return 'demo_at';
    }

    public function statusEnumClass(): string
    {
        return DemoStatus::class;
    }

    public function activeStatusValue(): \BackedEnum
    {
        return DemoStatus::Scheduled;
    }

    public function rescheduledStatusValue(): \BackedEnum
    {
        return DemoStatus::Rescheduled;
    }

    public function replacementAttributesForReschedule(): array
    {
        return [
            'prospect_id' => $this->prospect_id,
            'lead_id' => $this->lead_id,
            'assigned_to' => $this->assigned_to,
            'created_by' => $this->created_by,
            'mode' => $this->mode,
            'location' => $this->location,
            'meeting_link' => $this->meeting_link,
            'attendees' => $this->attendees,
            'product_service' => $this->product_service,
            'purpose' => $this->purpose,
        ];
    }

    /**
     * withoutGlobalScope(SoftDeletingScope) so a soft-deleted Prospect's
     * company name still resolves here — mirrors every other downstream
     * model's prospect() relation.
     */
    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class)->withoutGlobalScope(SoftDeletingScope::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function assignedEmployee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The prior, not-yet-conducted Demo this one replaced via an explicit Reschedule — never a completed one. */
    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rescheduled_from_id');
    }

    /** The Demo that replaced this one via an explicit Reschedule, if any — computed, not a second physical column. */
    public function replacedBy(): HasOne
    {
        return $this->hasOne(self::class, 'rescheduled_from_id');
    }

    /** Which prior workflow activity caused this Demo to be created as the next business action — lineage, not reschedule linkage. */
    public function origin(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The real Follow-Up created when this Demo's outcome/next_action was
     * "More Time / Discussion" / "Create Follow-Up" — found via that
     * Follow-Up's own origin_type/origin_id pointing back here, so the
     * schedule is never duplicated on this Demo (see
     * App\Services\WorkflowTransitionService).
     */
    public function generatedFollowUp(): HasOne
    {
        return $this->hasOne(FollowUp::class, 'origin_id')->where('origin_type', 'demo');
    }

    /**
     * Senior Managers see every Demo in their organization; Managers see
     * their own + their direct reports'; Employees see only their own —
     * identical to every other Phase 1 hierarchy-scoped resource.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return \App\Support\Authorization\HierarchyVisibility::scopeFor($query, $user, 'assigned_to');
    }

    public function isOverdue(): bool
    {
        return $this->status === DemoStatus::Scheduled
            && $this->demo_at !== null
            && $this->demo_at->isPast()
            && ! $this->demo_at->isToday();
    }

    public function isDueToday(): bool
    {
        return $this->status === DemoStatus::Scheduled
            && $this->demo_at !== null
            && $this->demo_at->isToday();
    }

    /** Inherits organization_id from the Lead this Demo is against. */
    protected function inheritedOrganizationId(): ?int
    {
        if (! $this->lead_id) {
            return null;
        }

        return DB::table('leads')->where('id', $this->lead_id)->value('organization_id');
    }

    /** @return array<string, array{0: string, 1: string}> */
    protected function organizationScopedRelations(): array
    {
        return [
            'prospect_id' => ['prospects', 'Prospect'],
            'lead_id' => ['leads', 'Lead'],
            'assigned_to' => ['users', 'assigned User'],
            'created_by' => ['users', 'creating User'],
            'rescheduled_from_id' => ['demos', 'original Demo'],
        ];
    }
}
