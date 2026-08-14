<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The Database module. Every company/contact prospect starts here — this is
 * the entry point for the entire sales workflow (AGENTS.md section 10).
 */
class Prospect extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_name',
        'contact_person',
        'designation',
        'telephone',
        'mobile',
        'email',
        'website',
        'address',
        'locality',
        'city',
        'state',
        'pincode',
        'industry',
        'source',
        'notes',
        'assigned_to',
        'created_by',
    ];

    public function assignedEmployee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function callRecords(): HasMany
    {
        return $this->hasMany(CallRecord::class)->latest('called_at');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    /**
     * Admins see every Prospect; Employees only see the ones currently
     * assigned to them. This is the enforcement point used by both the
     * Filament resource and any direct query, so a changed URL/record ID
     * can never leak another employee's records (AGENTS.md section 6/39).
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isAdmin() ? $query : $query->where('assigned_to', $user->id);
    }
}
