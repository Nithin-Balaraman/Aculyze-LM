<?php

namespace App\Providers;

use App\Models\Appointment;
use App\Models\CallRecord;
use App\Models\Demo;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\User;
use App\Observers\CallRecordObserver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Database\Eloquent\Relations\Relation;
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

        // Phase 2: stable lineage aliases for every origin_type/origin_id
        // (and reschedule) morph column — never persist raw class names,
        // so a future namespace/class rename can never corrupt historical
        // lineage meaning already stored in the database.
        Relation::enforceMorphMap([
            'call_record' => CallRecord::class,
            'follow_up' => FollowUp::class,
            'appointment' => Appointment::class,
            'lead' => Lead::class,
            'demo' => Demo::class,
            'proposal' => Proposal::class,
        ]);

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
