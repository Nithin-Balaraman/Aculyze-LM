<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\EnforcesSameOrganizationRelations;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * The Database module. Every company/contact prospect starts here — this is
 * the entry point for the entire sales workflow (AGENTS.md section 10).
 *
 * The top of the organization-inheritance chain (Phase 1) — a Prospect has
 * no parent business record to derive organization_id from, so
 * BelongsToOrganization falls back to the ambient TenantContext (the
 * creating user's own organization) for brand-new Prospects.
 */
class Prospect extends Model
{
    use BelongsToOrganization, EnforcesSameOrganizationRelations, HasFactory, SoftDeletes;

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

    protected static function booted(): void
    {
        static::addGlobalScope(new OrganizationScope);
    }

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
     * Senior Managers see every Prospect in their organization; Managers see
     * their own + their direct reports'; Employees see only their own. This
     * is the enforcement point used by both the Filament resource and any
     * direct query, so a changed URL/record ID can never leak another
     * employee's records (AGENTS.md section 6/39) — organization isolation
     * itself is enforced separately and first, by
     * App\Models\Scopes\OrganizationScope.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return \App\Support\Authorization\HierarchyVisibility::scopeFor($query, $user, 'assigned_to');
    }

    /**
     * The top of the organization-inheritance chain still has a deterministic
     * source to prefer over ambient TenantContext: created_by is always
     * required (never nullable, unlike assigned_to), so a Prospect's
     * organization is derived from its creator's own organization rather
     * than from whoever happens to be the current request's acting user —
     * these are normally the same person, but need not be (e.g. a future
     * admin-on-behalf-of creation path).
     */
    protected function inheritedOrganizationId(): ?int
    {
        if (! $this->created_by) {
            return null;
        }

        return DB::table('users')->where('id', $this->created_by)->value('organization_id');
    }

    /** @return array<string, array{0: string, 1: string}> */
    protected function organizationScopedRelations(): array
    {
        return [
            'assigned_to' => ['users', 'assigned User'],
            'created_by' => ['users', 'creating User'],
        ];
    }
}
