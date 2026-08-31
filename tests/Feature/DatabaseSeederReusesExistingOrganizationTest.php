<?php

namespace Tests\Feature;

use App\Models\Organization;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Found during Phase 2 manual verification: DatabaseSeeder used to call
 * Organization::create() unconditionally for the Aculyze organization,
 * which throws on the slug's unique constraint if that organization
 * already exists — e.g. after `php artisan aculyze:backfill-organizations`
 * was run separately before seeding, exactly the sequence a local upgrade
 * (migrate partway, backfill, then seed for local testing) can produce.
 * Fixed via Organization::firstOrCreate(), matching
 * BackfillOrganizations's own pattern.
 */
class DatabaseSeederReusesExistingOrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_after_the_aculyze_organization_already_exists_does_not_throw_and_reuses_it(): void
    {
        $this->artisan('aculyze:backfill-organizations')->assertSuccessful();
        $existing = Organization::where('slug', 'aculyze')->firstOrFail();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, Organization::where('slug', 'aculyze')->count());
        $this->assertSame($existing->id, Organization::where('slug', 'aculyze')->value('id'));
    }
}
