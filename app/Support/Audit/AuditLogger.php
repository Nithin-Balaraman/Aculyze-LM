<?php

namespace App\Support\Audit;

use App\Models\AuditEvent;
use Illuminate\Support\Facades\Auth;

/**
 * The single write path into audit_events (Phase 1 foundation) — never
 * create an AuditEvent directly, always through here, so the
 * organization/scope pairing invariant and sensitive-field redaction are
 * never something a call site could forget.
 */
class AuditLogger
{
    /**
     * Keys stripped entirely (not masked) from before/after payloads,
     * regardless of which entity is being audited — generic enough that a
     * future auditable model with a similarly-named sensitive field is
     * protected by default rather than by developer memory.
     */
    private const REDACTED_EXACT = ['password', 'remember_token'];

    private const REDACTED_PATTERN = '/token|secret|credential/i';

    /**
     * @param  int|null  $organizationId  null means this is a genuinely
     *     system-level/cross-organization event (see Tenancy::
     *     withoutScopeForSystemTask()) — never guess an organization just
     *     to satisfy a NOT NULL column; pass null and `scope` becomes
     *     'system' automatically.
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function record(
        string $entityType,
        ?int $entityId,
        string $action,
        ?int $organizationId,
        ?array $before = null,
        ?array $after = null,
        ?string $description = null,
    ): AuditEvent {
        $actor = Auth::user();

        return AuditEvent::create([
            'organization_id' => $organizationId,
            'scope' => $organizationId === null ? 'system' : 'organization',
            'actor_user_id' => $actor?->id,
            'actor_role_snapshot' => $actor?->role?->value,
            'actor_name_snapshot' => $actor?->name,
            'actor_email_snapshot' => $actor?->email,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'before' => $before === null ? null : static::redact($before),
            'after' => $after === null ? null : static::redact($after),
            'description' => $description,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function redact(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED_EXACT, true)) {
                continue;
            }

            if (preg_match(self::REDACTED_PATTERN, (string) $key) === 1) {
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
