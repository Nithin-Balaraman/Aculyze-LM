<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Found during Phase 2 manual verification: `audit_events` is created by a
 * later migration than `organizations` — the approved production runbook
 * itself inserts the Aculyze organization (Step C) before that table
 * exists (Step G), and locally `php artisan aculyze:backfill-organizations`
 * can likewise be invoked between those two migrations. Organization's own
 * `created`/`updated` audit hooks used to hard-fail with a missing-table
 * error in that window. Fixed via Organization::auditEventsTableExists().
 */
class OrganizationAuditTableRaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_organization_succeeds_even_if_audit_events_does_not_exist_yet(): void
    {
        Schema::drop('audit_events');

        $organization = Organization::create([
            'name' => 'Aculyze Solutions',
            'slug' => 'aculyze-race-test',
            'timezone' => 'Asia/Kolkata',
        ]);

        $this->assertNotNull($organization->id);
        $this->assertDatabaseHas('organizations', ['id' => $organization->id]);
    }

    public function test_updating_settings_succeeds_even_if_audit_events_does_not_exist_yet(): void
    {
        $organization = Organization::factory()->create();
        Schema::drop('audit_events');

        $organization->update(['settings' => ['feature_x' => true]]);

        $this->assertSame(['feature_x' => true], $organization->fresh()->settings);
    }

    public function test_auditing_resumes_normally_once_audit_events_exists(): void
    {
        $organization = Organization::factory()->create();

        $event = AuditEvent::withoutGlobalScopes()
            ->where('entity_type', Organization::AUDIT_ENTITY_TYPE)
            ->where('entity_id', $organization->id)
            ->where('action', 'organization_created')
            ->first();

        $this->assertNotNull($event, 'Normal creation (with audit_events present) must still be audited.');
    }
}
