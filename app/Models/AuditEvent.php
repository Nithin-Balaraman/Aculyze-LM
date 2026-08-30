<?php

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The Phase 1 audit-trail foundation — see the 2026_08_30_090600_create_
 * audit_events_table migration's docblock for the schema rationale
 * (nullable organization_id + scope, generic entity_type/action shape).
 *
 * Write-only through App\Support\Audit\AuditLogger, never directly — the
 * logger is what enforces the organization/scope pairing invariant and
 * applies sensitive-field redaction before anything reaches this model.
 *
 * Immutable through normal application use: no `updated_at` column, and
 * save() refuses to run against an already-persisted row (defensive, on
 * top of simply never exposing an edit UI/action for this model anywhere).
 */
class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'scope',
        'actor_user_id',
        'actor_role_snapshot',
        'actor_name_snapshot',
        'actor_email_snapshot',
        'entity_type',
        'entity_id',
        'action',
        'before',
        'after',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Attached only now that this table/model exist (Phase 1 implementation
     * order fix) — a `scope = 'system'` row has organization_id = null,
     * which never matches this scope's `WHERE organization_id = X` filter,
     * so such rows are structurally invisible to any normal organization-
     * scoped audit query rather than merely policy-hidden. Global scopes
     * apply only to SELECT/UPDATE/DELETE queries, never to the INSERT
     * App\Support\Audit\AuditLogger performs, so writing a system-scope
     * event works regardless of whether a TenantContext is set.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new OrganizationScope);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function save(array $options = [])
    {
        if ($this->exists) {
            throw new LogicException('Audit events are immutable — they cannot be updated after creation.');
        }

        return parent::save($options);
    }
}
