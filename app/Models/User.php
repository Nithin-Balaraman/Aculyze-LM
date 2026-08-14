<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable implements FilamentUser, HasAvatar
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'avatar',
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
        ];
    }
}
