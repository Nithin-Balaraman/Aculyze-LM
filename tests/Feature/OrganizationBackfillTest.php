<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 1: php artisan aculyze:backfill-organizations must be deterministic
 * (always the same Aculyze organization), idempotent (safe to re-run), and
 * non-destructive (only ever fills a currently-NULL organization_id, never
 * overwrites an already-populated one). This exercises the command
 * directly against rows created by deliberately bypassing the normal
 * auto-fill (via the system bypass), simulating genuinely pre-existing,
 * not-yet-backfilled data the way it would look on a real upgrade.
 */
class OrganizationBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_creates_the_aculyze_organization_and_populates_null_rows(): void
    {
        // Simulate a pre-Phase-1 row: organization_id genuinely NULL, as it
        // would be on a real production table before this command runs.
        // The column is NOT NULL in a freshly-migrated test database (the
        // final tightening step already ran), so it's relaxed here just
        // for this one test — safe, since RefreshDatabase fully re-migrates
        // before the next test regardless.
        DB::statement('ALTER TABLE users MODIFY organization_id BIGINT UNSIGNED NULL');
        DB::table('audit_events')->delete();
        DB::table('organizations')->delete();
        $userId = DB::table('users')->insertGetId([
            'name' => 'Legacy User',
            'email' => 'legacy@example.test',
            'password' => bcrypt('password'),
            'role' => 'employee',
            'organization_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('aculyze:backfill-organizations')->assertSuccessful();

        $organization = Organization::where('slug', 'aculyze')->first();
        $this->assertNotNull($organization);
        $this->assertSame('Aculyze Solutions', $organization->name);

        $this->assertSame(
            $organization->id,
            DB::table('users')->where('id', $userId)->value('organization_id')
        );
    }

    public function test_backfill_is_idempotent_and_safe_to_re_run(): void
    {
        DB::statement('ALTER TABLE users MODIFY organization_id BIGINT UNSIGNED NULL');
        DB::table('audit_events')->delete();
        DB::table('organizations')->delete();
        $userId = DB::table('users')->insertGetId([
            'name' => 'Legacy User',
            'email' => 'legacy2@example.test',
            'password' => bcrypt('password'),
            'role' => 'employee',
            'organization_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('aculyze:backfill-organizations')->assertSuccessful();
        $firstOrganizationId = DB::table('users')->where('id', $userId)->value('organization_id');

        // Re-running must not create a second organization or change the
        // already-populated value.
        $this->artisan('aculyze:backfill-organizations')->assertSuccessful();

        $this->assertSame(1, Organization::where('slug', 'aculyze')->count());
        $this->assertSame($firstOrganizationId, DB::table('users')->where('id', $userId)->value('organization_id'));
    }

    public function test_backfill_never_overwrites_an_already_populated_organization_id(): void
    {
        $existingOrganization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();

        $userId = Tenancy::runAs($otherOrganization->id, fn () => DB::table('users')->insertGetId([
            'name' => 'Already Scoped User',
            'email' => 'scoped@example.test',
            'password' => bcrypt('password'),
            'role' => 'employee',
            'organization_id' => $otherOrganization->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->artisan('aculyze:backfill-organizations')->assertSuccessful();

        // Still points at its own, pre-existing organization — not
        // silently reassigned to Aculyze just because the command ran.
        $this->assertSame($otherOrganization->id, DB::table('users')->where('id', $userId)->value('organization_id'));
        $this->assertNotEquals($existingOrganization->id, $otherOrganization->id);
    }
}
