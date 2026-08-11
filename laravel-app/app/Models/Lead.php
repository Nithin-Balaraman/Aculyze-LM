<?php

namespace App\Models;

use App\Enums\LeadStage;
use App\Enums\LeadTemperature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        });
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
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
     * A Lead is stale once it has sat in the same active (non-terminal)
     * stage for 30+ days without moving. See AGENTS.md sections 22-23.
     */
    public function isStale(): bool
    {
        if ($this->stage->isTerminal() || $this->stage_changed_at === null) {
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
            ->whereNotIn('stage', array_map(
                fn (LeadStage $stage) => $stage->value,
                array_filter(LeadStage::cases(), fn (LeadStage $stage) => $stage->isTerminal())
            ))
            ->whereNotNull('stage_changed_at')
            ->where('stage_changed_at', '<=', $threshold);
    }
}
