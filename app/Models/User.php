<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Audit\AuditLogger;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * Deliberately NOT scoped by App\Models\Scopes\OrganizationScope (Phase 1)
 * — authentication must be able to look a user up by email/password before
 * any organization context exists. User still carries organization_id (via
 * BelongsToOrganization's creating-hook auto-fill, applied here for
 * convenience without ever attaching the read-time global scope) and
 * manager_id (the Employee/Manager/Senior Manager hierarchy edge); every
 * place User visibility or the manager relationship matters checks
 * organization_id equality explicitly instead — see
 * App\Support\Authorization\HierarchyVisibility and this model's own
 * booted() guard below.
 */
class User extends Authenticatable implements FilamentUser, HasAvatar
{
    /**
     * Stable audit entity-type identifier — deliberately not this class's
     * own ::class name, so a future namespace/class rename never
     * invalidates historical audit_events rows (Phase 1 plan).
     */
    public const AUDIT_ENTITY_TYPE = 'user';

    /** @use HasFactory<UserFactory> */
    use BelongsToOrganization, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'avatar',
        'manager_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /** Alias of isAdmin() — Admin is the Senior Manager tier (see UserRole's docblock). */
    public function isSeniorManager(): bool
    {
        return $this->isAdmin();
    }

    public function isManager(): bool
    {
        return $this->role === UserRole::Manager;
    }

    public function isEmployee(): bool
    {
        return $this->role === UserRole::Employee;
    }

    /** The tier this user's manager_id must point to, or null for Senior Manager (who has none). */
    private function requiredManagerRole(): ?UserRole
    {
        return match ($this->role) {
            UserRole::Employee => UserRole::Manager,
            UserRole::Manager => UserRole::Admin,
            UserRole::Admin => null,
        };
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    /**
     * Server-side hierarchy invariants — never relies on Filament UI
     * filtering alone (Phase 1 plan, "Manager relationship validation").
     * Mirrors the existing mandatory-field guard pattern already used
     * throughout this app (CallRecord/Appointment/Lead/Proposal's own
     * booted() methods): a hard model-level throw, with a friendly
     * Filament-form validation layered on top purely for UX.
     */
    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            static::guardOutgoingManagerAssignment($user);
            static::guardIncomingHierarchyChanges($user);
        });

        // Phase 1 audit foundation: only the hierarchy/lifecycle events
        // this phase itself introduces — see App\Support\Audit\
        // AuditLogger's docblock. Runs on `saved` (after the write
        // actually succeeds), using wasChanged() rather than isDirty()
        // since original attributes are already synced by this point.
        static::created(function (self $user): void {
            AuditLogger::record(
                self::AUDIT_ENTITY_TYPE,
                $user->id,
                'user_created',
                $user->organization_id,
                after: ['name' => $user->name, 'email' => $user->email, 'role' => $user->role->value, 'manager_id' => $user->manager_id],
            );
        });

        // updated() — not saved() + a wasRecentlyCreated check — since
        // wasRecentlyCreated stays true on the same in-memory instance
        // across any later save() within the same request/process (it only
        // resets when the model is re-fetched from the database), which
        // would otherwise silently skip logging a real subsequent change
        // made without an intervening fresh() call. updated() fires only
        // when this specific save cycle was genuinely an UPDATE, which is
        // exactly the distinction needed, with no extra flag required.
        static::updated(function (self $user): void {
            if (! $user->wasChanged('role')) {
                return;
            }

            AuditLogger::record(
                self::AUDIT_ENTITY_TYPE,
                $user->id,
                'role_changed',
                $user->organization_id,
                before: ['role' => $user->getRawOriginal('role')],
                after: ['role' => $user->role->value],
            );
        });

        static::updated(function (self $user): void {
            if (! $user->wasChanged('manager_id')) {
                return;
            }

            AuditLogger::record(
                self::AUDIT_ENTITY_TYPE,
                $user->id,
                'manager_changed',
                $user->organization_id,
                before: ['manager_id' => $user->getOriginal('manager_id')],
                after: ['manager_id' => $user->manager_id],
            );
        });

        static::deleted(function (self $user): void {
            AuditLogger::record(
                self::AUDIT_ENTITY_TYPE,
                $user->id,
                'user_deleted',
                $user->organization_id,
                before: ['name' => $user->name, 'email' => $user->email, 'role' => $user->role->value],
            );
        });
    }

    /**
     * Outgoing: is manager_id itself a valid target? Same organization,
     * correct tier (Employee->Manager, Manager->Senior Manager, Senior
     * Manager->none), no self-reference, no circular chain.
     */
    private static function guardOutgoingManagerAssignment(self $user): void
    {
        if ($user->role === UserRole::Admin && $user->manager_id !== null) {
            throw new LogicException('A Senior Manager cannot report to anyone.');
        }

        if ($user->manager_id === null) {
            return;
        }

        if ($user->manager_id === $user->id) {
            throw new LogicException('A user cannot report to themselves.');
        }

        $manager = static::query()->find($user->manager_id);

        if (! $manager) {
            throw new LogicException('The selected manager does not exist.');
        }

        if ($manager->organization_id !== $user->organization_id) {
            throw new LogicException('A user can only report to a manager within the same organization.');
        }

        $expectedManagerRole = $user->requiredManagerRole();

        if ($expectedManagerRole === null || $manager->role !== $expectedManagerRole) {
            $expectedLabel = $expectedManagerRole?->getLabel() ?? 'no one';

            throw new LogicException(
                "A {$user->role->getLabel()} must report to a {$expectedLabel}, not a {$manager->role->getLabel()}."
            );
        }

        // Cycle check: walk up from the proposed manager; this user must
        // never appear in that chain. At most two hops given the fixed
        // three-tier structure, but walked generically/defensively rather
        // than assumed.
        $cursor = $manager;
        $visited = [];

        while ($cursor?->manager_id !== null) {
            if ($cursor->manager_id === $user->id || in_array($cursor->manager_id, $visited, true)) {
                throw new LogicException('This manager assignment would create a circular reporting relationship.');
            }

            $visited[] = $cursor->manager_id;
            $cursor = static::query()->find($cursor->manager_id);
        }
    }

    /**
     * Incoming: does a role or organization change on THIS user invalidate
     * users who already report to them, or strand records they already
     * own? Rejects the change outright rather than guessing a
     * reassignment — the caller must explicitly resolve those first.
     */
    private static function guardIncomingHierarchyChanges(self $user): void
    {
        if (! $user->exists) {
            return;
        }

        if ($user->isDirty('role')) {
            foreach ($user->directReports as $report) {
                $expectedManagerRole = $report->requiredManagerRole();

                if ($expectedManagerRole === null || $user->role !== $expectedManagerRole) {
                    throw new LogicException(
                        "Cannot change role while {$report->name} still reports to this user as their ".
                        ($expectedManagerRole?->getLabel() ?? 'manager').'. Reassign direct reports first.'
                    );
                }
            }
        }

        if ($user->isDirty('organization_id')) {
            if ($user->directReports()->exists()) {
                throw new LogicException('Cannot change organization while this user still has direct reports. Reassign them first.');
            }

            if ($user->manager_id !== null) {
                $manager = static::query()->find($user->manager_id);

                if ($manager && $manager->organization_id !== $user->organization_id) {
                    throw new LogicException(
                        'Cannot change organization while this user still reports to a manager in a different organization. Clear the manager relationship first.'
                    );
                }
            }

            $oldOrganizationId = $user->getOriginal('organization_id');

            if ($oldOrganizationId !== null && static::ownsRecordsInOrganization($user->id, $oldOrganizationId)) {
                throw new LogicException(
                    'Cannot change organization while records in the previous organization are still assigned to or created by this user. Reassign them first.'
                );
            }
        }
    }

    /**
     * @param  array<int, array{0: string, 1: array<int, string>}>  $ownershipColumnsByTable
     */
    private static function ownsRecordsInOrganization(int $userId, int $organizationId): bool
    {
        $ownershipColumnsByTable = [
            ['prospects', ['assigned_to', 'created_by']],
            ['call_records', ['user_id']],
            ['follow_ups', ['user_id']],
            ['appointments', ['assigned_to', 'created_by']],
            ['leads', ['assigned_to', 'created_by']],
            ['proposals', ['assigned_to', 'created_by']],
            ['export_requests', ['user_id']],
        ];

        foreach ($ownershipColumnsByTable as [$table, $columns]) {
            $exists = DB::table($table)
                ->where('organization_id', $organizationId)
                ->where(function ($query) use ($columns, $userId) {
                    foreach ($columns as $column) {
                        $query->orWhere($column, $userId);
                    }
                })
                ->exists();

            if ($exists) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns null when no avatar has been uploaded, which Filament falls
     * back on to render App\Filament\AvatarProviders\InitialsAvatarProvider
     * (the panel's registered default) instead — so every user still gets a
     * sensible avatar even before uploading one.
     */
    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatar ? Storage::disk('avatars')->url($this->avatar) : null;
    }

    /**
     * Any authenticated user (Admin or Employee) may access the panel.
     * Fine-grained page/resource/record access is enforced separately by
     * policies and per-resource canAccess() checks.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    // Records this user currently owns/created, across the workflow.

    public function assignedProspects(): HasMany
    {
        return $this->hasMany(Prospect::class, 'assigned_to');
    }

    public function createdProspects(): HasMany
    {
        return $this->hasMany(Prospect::class, 'created_by');
    }

    public function callRecords(): HasMany
    {
        return $this->hasMany(CallRecord::class);
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    public function assignedAppointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'assigned_to');
    }

    public function assignedLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'assigned_to');
    }

    public function assignedProposals(): HasMany
    {
        return $this->hasMany(Proposal::class, 'assigned_to');
    }

    public function createdAppointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'created_by');
    }

    public function createdLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'created_by');
    }

    public function createdProposals(): HasMany
    {
        return $this->hasMany(Proposal::class, 'created_by');
    }

    /**
     * Every FK on this user (assigned_to/created_by/user_id across every
     * module) is a plain RESTRICT constraint, so deleting a User with any of
     * these still attached would otherwise fail as a raw DB error. Named so
     * a blocked-delete notification can list exactly what's still attached
     * (Change Request Section 5).
     *
     * This array both decides whether UserResource's bulk-delete is
     * blocked AND supplies the notification's counts (App\Support\
     * DeletionGuard::guardRecords()), so 'Call Record(s)' must reflect
     * every Call Record: bulk delete has no reassignment step, so
     * blocking on the true count is what stops a Call Record's user_id
     * being left pointing at a deleted employee.
     *
     * @return array<string, int>
     */
    public function deletionBlockers(): array
    {
        return [
            'assigned Prospect(s)' => $this->assignedProspects()->count(),
            'created Prospect(s)' => $this->createdProspects()->count(),
            'Call Record(s)' => $this->callRecords()->count(),
            'Follow-Up(s)' => $this->followUps()->count(),
            'assigned Appointment(s)' => $this->assignedAppointments()->count(),
            'created Appointment(s)' => $this->createdAppointments()->count(),
            'assigned Lead(s)' => $this->assignedLeads()->count(),
            'created Lead(s)' => $this->createdLeads()->count(),
            'assigned Proposal(s)' => $this->assignedProposals()->count(),
            'created Proposal(s)' => $this->createdProposals()->count(),
            'direct Report(s)' => $this->directReports()->count(),
        ];
    }
}
