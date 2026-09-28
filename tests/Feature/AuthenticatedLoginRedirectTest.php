<?php

namespace Tests\Feature;

use App\Filament\Resources\ExportRequestResource;
use App\Models\ExportRequest;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Production log (2026-09-22 and 2026-09-28): an already-authenticated
 * user hitting /admin/login got a 500 instead of the intended redirect.
 * Root cause, reproduced directly (not assumed) by explicitly forgetting
 * TenantContext before the request — TestCase::setUp() otherwise seeds an
 * ambient context for every test, which was masking this exact scenario:
 * Filament\Pages\Auth\Login::mount() calls redirect()->intended(...) for
 * an authenticated user, but before that response completes it still
 * builds the panel's navigation (badges included), and the Login route
 * itself runs outside authMiddleware (see EstablishTenantContext's own
 * docblock) — the only thing that could have set TenantContext by then is
 * the Authenticated event listener in AppServiceProvider, which does not
 * reliably beat this exact code path. ExportRequestResource::
 * getNavigationBadge() was the one resource in the whole app implementing
 * getNavigationBadge() at all (confirmed by grep across app/Filament/
 * Resources and app/Filament/Pages) — every other resource has no
 * navigation-badge query to guard.
 */
class AuthenticatedLoginRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_requesting_login_is_redirected_without_an_error(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        // The exact condition that reproduced the crash: no tenant context
        // established yet, matching the real request's actual state at
        // this route (TestCase::setUp() otherwise ambiently sets one for
        // every test, which would silently hide this regression).
        TenantContext::forget();

        $response = $this->get('/admin/login');

        $response->assertRedirect();
        $this->assertLessThan(500, $response->getStatusCode());
    }

    public function test_navigation_badge_returns_null_with_no_tenant_context(): void
    {
        TenantContext::forget();

        $this->assertNull(ExportRequestResource::getNavigationBadge());
    }

    /**
     * The guard must not swallow real pending counts once a context IS
     * present — it only short-circuits when there's genuinely none.
     */
    public function test_navigation_badge_still_counts_pending_requests_with_a_tenant_context(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create(['organization_id' => $admin->organization_id]);

        Tenancy::runAs($admin->organization_id, function () use ($employee) {
            ExportRequest::create([
                'user_id' => $employee->id,
                'resource' => 'lead',
                'filters' => [],
                'status' => 'pending',
            ]);

            $this->assertSame('1', ExportRequestResource::getNavigationBadge());
        });
    }
}
