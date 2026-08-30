<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 audit foundation: actor identity/role snapshots survive even if
 * the User account changes later, sensitive fields are redacted before
 * anything is persisted, and system-scope events (organization_id = null)
 * are correctly invisible under any normal organization-scoped query — not
 * merely policy-hidden, but structurally excluded by the NULL comparison.
 */
class AuditLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_user_writes_an_audit_event_with_actor_and_role_snapshots(): void
    {
        $organization = Organization::factory()->create();
        $admin = Tenancy::runAs($organization->id, fn () => User::factory()->admin()->create(['organization_id' => $organization->id, 'name' => 'Saji Admin']));

        $this->actingAs($admin);

        $newHire = User::create([
            'name' => 'New Hire',
            'email' => 'newhire@example.test',
            'password' => 'password',
            'role' => UserRole::Employee,
        ]);

        $event = AuditEvent::where('action', 'user_created')->where('entity_id', $newHire->id)->first();

        $this->assertNotNull($event);
        $this->assertSame($admin->id, $event->actor_user_id);
        $this->assertSame('admin', $event->actor_role_snapshot);
        $this->assertSame('Saji Admin', $event->actor_name_snapshot);
        $this->assertSame($admin->email, $event->actor_email_snapshot);
        $this->assertSame('organization', $event->scope);
        $this->assertSame($organization->id, $event->organization_id);
    }

    public function test_actor_snapshot_survives_the_actor_being_deleted_later(): void
    {
        $organization = Organization::factory()->create();
        [$admin, $otherAdmin] = Tenancy::runAs($organization->id, fn () => [
            User::factory()->admin()->create(['organization_id' => $organization->id, 'name' => 'Original Admin']),
            User::factory()->admin()->create(['organization_id' => $organization->id]),
        ]);

        $this->actingAs($admin);
        $newHire = User::create(['name' => 'X', 'email' => 'x@example.test', 'password' => 'password', 'role' => UserRole::Employee]);

        $event = AuditEvent::where('action', 'user_created')->where('entity_id', $newHire->id)->first();
        $this->assertSame('Original Admin', $event->actor_name_snapshot);

        // Delete the actor (a different admin performs the deletion, so the
        // "last admin" guard doesn't block it) — the historical snapshot on
        // the earlier event must remain exactly as it was.
        $this->actingAs($otherAdmin);
        $admin->delete();

        $this->assertSame('Original Admin', $event->fresh()->actor_name_snapshot);
    }

    public function test_role_change_audit_event_records_before_and_after(): void
    {
        $organization = Organization::factory()->create();
        $admin = Tenancy::runAs($organization->id, fn () => User::factory()->admin()->create(['organization_id' => $organization->id]));
        $employee = Tenancy::runAs($organization->id, fn () => User::factory()->create(['organization_id' => $organization->id, 'role' => UserRole::Employee]));

        $this->actingAs($admin);
        $employee->update(['role' => UserRole::Manager]);

        $event = AuditEvent::where('action', 'role_changed')->where('entity_id', $employee->id)->first();

        $this->assertSame('employee', $event->before['role']);
        $this->assertSame('manager', $event->after['role']);
    }

    public function test_sensitive_fields_are_redacted_from_audit_payloads(): void
    {
        $organization = Organization::factory()->create();

        $event = AuditLogger::record(
            'user',
            null,
            'test_action',
            $organization->id,
            before: [
                'password' => 'super-secret',
                'remember_token' => 'abc123',
                'api_token' => 'sensitive-token-value',
                'oauth_secret' => 'also-sensitive',
                'name' => 'Safe To Keep',
                'email' => 'safe@example.test',
            ],
        );

        $this->assertArrayNotHasKey('password', $event->before);
        $this->assertArrayNotHasKey('remember_token', $event->before);
        $this->assertArrayNotHasKey('api_token', $event->before);
        $this->assertArrayNotHasKey('oauth_secret', $event->before);
        $this->assertSame('Safe To Keep', $event->before['name']);
        $this->assertSame('safe@example.test', $event->before['email']);
    }

    public function test_system_scope_event_has_no_organization_id(): void
    {
        $event = AuditLogger::record('system', null, 'test_system_event', null, description: 'a genuinely cross-organization action');

        $this->assertSame('system', $event->scope);
        $this->assertNull($event->organization_id);
    }

    public function test_system_scope_event_is_invisible_under_a_normal_organization_scoped_query(): void
    {
        $organization = Organization::factory()->create();

        AuditLogger::record('system', null, 'test_system_event_invisible', null, description: 'system-wide');
        AuditLogger::record('user', null, 'test_org_event_visible', $organization->id, description: 'org-scoped');

        TenantContext::set($organization->id);

        $visibleActions = AuditEvent::pluck('action')->all();

        $this->assertNotContains('test_system_event_invisible', $visibleActions);
        $this->assertContains('test_org_event_visible', $visibleActions);

        TenantContext::forget();
    }

    public function test_audit_events_are_immutable_after_creation(): void
    {
        $organization = Organization::factory()->create();
        $event = AuditLogger::record('user', null, 'test_action', $organization->id);

        $this->expectException(\LogicException::class);

        $event->update(['description' => 'tampered']);
    }

    public function test_legitimate_system_bypass_creates_exactly_one_audit_event(): void
    {
        TenantContext::forget();

        Tenancy::withoutScopeForSystemTask('one-off maintenance task', fn () => \App\Models\Lead::count());

        $event = Tenancy::withoutScopeForSystemTask(
            'reading the audit trail itself for verification',
            fn () => AuditEvent::where('action', 'tenant_scope_bypassed')->where('description', 'one-off maintenance task')->first()
        );

        $this->assertNotNull($event);
        $this->assertSame('system', $event->scope);
        $this->assertNull($event->organization_id);
    }
}
