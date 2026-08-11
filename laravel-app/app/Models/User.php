<?php

namespace App\Models;

use App\Enums\UserRole;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
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
}
