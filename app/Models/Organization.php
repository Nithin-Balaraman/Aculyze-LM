<?php

namespace App\Models;

use App\Support\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant boundary (Phase 1 foundation). Aculyze Solutions is the
 * initial organization; every organization-owned model (see
 * App\Models\Concerns\BelongsToOrganization) is scoped to exactly one row
 * here via App\Models\Scopes\OrganizationScope.
 *
 * Deliberately NOT itself organization-scoped (it IS the boundary), and
 * User is deliberately NOT related here via a scoped query — see
 * BelongsToOrganization's docblock for why User carries organization_id
 * without carrying OrganizationScope.
 */
class Organization extends Model
{
    public const AUDIT_ENTITY_TYPE = 'organization';

    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'timezone',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (self $organization): void {
            if (! self::auditEventsTableExists()) {
                return;
            }

            AuditLogger::record(
                self::AUDIT_ENTITY_TYPE,
                $organization->id,
                'organization_created',
                $organization->id,
                after: ['name' => $organization->name, 'slug' => $organization->slug, 'timezone' => $organization->timezone],
            );
        });

        // updated() rather than saved() + wasRecentlyCreated — see
        // App\Models\User's identical fix: wasRecentlyCreated stays true on
        // the same in-memory instance across any later save() within the
        // same request, so it cannot reliably distinguish "this save cycle
        // was the initial insert" from "a later update on the same object."
        static::updated(function (self $organization): void {
            if (! $organization->wasChanged('settings')) {
                return;
            }

            if (! self::auditEventsTableExists()) {
                return;
            }

            AuditLogger::record(
                self::AUDIT_ENTITY_TYPE,
                $organization->id,
                'module_setting_changed',
                $organization->id,
                before: ['settings' => $organization->getOriginal('settings')],
                after: ['settings' => $organization->settings],
            );
        });
    }

    /**
     * `audit_events` is created by a later migration than `organizations`
     * — the approved production runbook itself inserts the Aculyze
     * organization (Step C) before that table exists (Step G), and
     * locally, `php artisan aculyze:backfill-organizations` can likewise
     * be invoked between those two migrations. Without this guard, the
     * very first Organization created on a not-yet-fully-migrated database
     * would fail entirely on a missing-table error from this audit hook —
     * a chicken-and-egg failure that has nothing to do with whether the
     * organization itself is valid. Skips the audit write (never the
     * organization write) when the table isn't there yet; once
     * `audit_events` exists, every subsequent create/settings-change is
     * audited normally.
     */
    private static function auditEventsTableExists(): bool
    {
        return Schema::hasTable('audit_events');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function prospects(): HasMany
    {
        return $this->hasMany(Prospect::class);
    }
}
