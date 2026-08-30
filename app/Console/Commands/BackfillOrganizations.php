<?php

namespace App\Console\Commands;

use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1 foundation: creates the single Aculyze Organization (if it does
 * not already exist) and backfills organization_id on every pre-existing
 * row across the organization-owned tables. Deterministic, idempotent
 * (safe to re-run — every UPDATE is guarded by `WHERE organization_id IS
 * NULL`), and non-destructive (only ever sets a currently-NULL column,
 * never overwrites an already-populated one).
 *
 * This is the local/development/future-environment equivalent of the
 * manual phpMyAdmin SQL runbook documented for the current Hostinger
 * production environment (which has no Artisan/SSH access) — both must
 * stay logically identical.
 *
 * Deliberately does NOT need App\Support\Tenancy\Tenancy::
 * withoutScopeForSystemTask() — it runs before OrganizationScope is
 * attached to any model (see the migration ordering in the Phase 1 plan),
 * so there is no scope yet to bypass.
 */
class BackfillOrganizations extends Command
{
    protected $signature = 'aculyze:backfill-organizations';

    protected $description = 'Create the Aculyze organization (if missing) and backfill organization_id on every existing business record';

    private array $tables = [
        'users',
        'prospects',
        'call_records',
        'follow_ups',
        'appointments',
        'leads',
        'proposals',
        'export_requests',
    ];

    public function handle(): int
    {
        $organization = Organization::query()->firstOrCreate(
            ['slug' => 'aculyze'],
            ['name' => 'Aculyze Solutions', 'timezone' => 'Asia/Kolkata']
        );

        $this->info("Aculyze organization id: {$organization->id}");

        foreach ($this->tables as $table) {
            $updated = DB::table($table)
                ->whereNull('organization_id')
                ->update(['organization_id' => $organization->id]);

            $this->info("{$table}: backfilled {$updated} row(s).");
        }

        $this->verify($organization->id);

        return self::SUCCESS;
    }

    private function verify(int $organizationId): void
    {
        $problems = [];

        foreach ($this->tables as $table) {
            $remaining = DB::table($table)->whereNull('organization_id')->count();

            if ($remaining > 0) {
                $problems[] = "{$table}: {$remaining} row(s) still have a NULL organization_id";
            }
        }

        if ($problems !== []) {
            $this->error('Backfill verification failed:');

            foreach ($problems as $problem) {
                $this->error(" - {$problem}");
            }

            return;
        }

        $this->info('Verification passed: every applicable table has zero NULL organization_id rows.');
    }
}
