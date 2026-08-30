<?php

namespace Tests;

use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every test gets a valid ambient TenantContext from the start, not
     * just tests that happen to call actingAs() before touching an
     * organization-owned model — several pre-Phase-1 tests exercise pure
     * business logic (e.g. CallRoutingService) by creating fixtures
     * directly and reading them back (->fresh(), relationship access)
     * without ever authenticating anyone. Reusing whichever Organization
     * already exists in the (freshly refreshed) test database — the same
     * "reuse-or-create-once" convention database/factories/UserFactory.php
     * and ProspectFactory.php's created_by-derived inheritance already
     * rely on — gives every test a single, consistent default organization
     * with zero changes to the tests themselves. A test that explicitly
     * wants a second organization for isolation testing creates one and
     * switches context to it (or calls actingAs() with a user in it).
     */
    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::set(
            Organization::query()->value('id') ?? Organization::factory()->create()->id
        );
    }

    /**
     * actingAs() additionally switches TenantContext to the acting user's
     * own organization — mirroring what
     * App\Http\Middleware\EstablishTenantContext does for a real request —
     * so a test that explicitly authenticates as a user in a second,
     * deliberately-created organization gets that organization's context,
     * not the setUp() default.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        TenantContext::set($user->organization_id ?? null);

        return parent::actingAs($user, $guard);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }
}
