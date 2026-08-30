<?php

namespace App\Providers;

use App\Models\CallRecord;
use App\Models\User;
use App\Observers\CallRecordObserver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        CallRecord::observe(CallRecordObserver::class);

        // App\Http\Middleware\EstablishTenantContext covers every
        // subsequent authenticated request, but Filament computes the
        // sidebar (including navigation badges like ExportRequestResource's
        // pending count) while building the LOGIN response itself — the
        // same request that just authenticated, before that request is
        // ever routed through authMiddleware. Authenticated fires the
        // moment any guard resolves a user, on every request (fresh login
        // or session resumption alike), which is what actually needs to
        // set TenantContext this early; the middleware remains for its
        // request-scoped forget() cleanup.
        $this->app['events']->listen(Authenticated::class, function (Authenticated $event): void {
            if ($event->user instanceof User) {
                TenantContext::set($event->user->organization_id);
            }
        });
    }
}
