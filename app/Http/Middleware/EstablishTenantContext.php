<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes App\Support\Tenancy\TenantContext for the duration of an
 * authenticated web request, from the acting user's own organization_id —
 * never from a query parameter or any other client-supplied value.
 *
 * Runs inside the Filament panel's authMiddleware, after Authenticate, so
 * auth()->user() is already resolved. Wrapped in try/finally so context is
 * cleared even if the request throws, and so it never survives into a
 * later request under a persistent-worker execution model (e.g. Octane) —
 * under classic PHP-FPM this is technically redundant per-request but is
 * required regardless for queue-worker/long-running-process safety, which
 * this middleware does not itself run under (see App\Support\Tenancy\
 * Tenancy::runAs(), the pattern future Job classes must use instead).
 *
 * User is deliberately not organization-scoped (see BelongsToOrganization's
 * docblock), so reading organization_id off the authenticated user here is
 * safe and does not depend on OrganizationScope being satisfiable yet.
 *
 * Complemented by an Illuminate\Auth\Events\Authenticated listener in
 * App\Providers\AppServiceProvider — that event fires the moment any guard
 * resolves a user, including within the very request that just logged in
 * (before that request is ever routed through this middleware), which
 * matters because Filament computes the sidebar/navigation badges as part
 * of building the login redirect response itself. This middleware remains
 * the mechanism for every subsequent request and for the request-scoped
 * forget() cleanup in its finally block.
 */
class EstablishTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        TenantContext::set($user->organization_id);

        try {
            return $next($request);
        } finally {
            TenantContext::forget();
        }
    }
}
