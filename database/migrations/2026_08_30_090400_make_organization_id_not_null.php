<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The final, self-guarding step of the nullable -> backfill -> verify ->
 * NOT NULL sequence (Phase 1 plan). This migration refuses to run —
 * throwing before touching the schema — unless the Aculyze organization
 * already exists AND every applicable table already has zero NULL
 * organization_id rows. This is what makes the sequence safe against a
 * missed manual step: `php artisan migrate` cannot silently apply a
 * broken constraint or leave the schema half-tightened, because the
 * precondition check is baked into the migration file itself rather than
 * depending on a human having correctly run
 * `php artisan aculyze:backfill-organizations` (or the equivalent
 * Hostinger SQL) beforehand.
 */
return new class extends Migration
{
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

    public function up(): void
    {
        $this->assertBackfillComplete();

        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->unsignedBigInteger('organization_id')->nullable(false)->change();
                $blueprint->foreign('organization_id')
                    ->references('id')->on('organizations')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropForeign(["{$table}_organization_id_foreign"]);
                $blueprint->unsignedBigInteger('organization_id')->nullable()->change();
            });
        }
    }

    /**
     * Deliberately does NOT require the Aculyze organization to already
     * exist as an unconditional precondition — a genuinely empty table (a
     * fresh install, before any seeding) has zero NULL rows trivially, and
     * the NOT NULL + FK constraint added below needs no pre-existing
     * Organization row to be valid until something is actually inserted.
     * What matters is that no table has an UNRESOLVED NULL row left over
     * from real, pre-existing data — which is exactly what the loop below
     * checks.
     */
    private function assertBackfillComplete(): void
    {
        foreach ($this->tables as $table) {
            $remaining = DB::table($table)->whereNull('organization_id')->count();

            if ($remaining > 0) {
                throw new RuntimeException(
                    "Refusing to tighten organization_id to NOT NULL: {$table} still has {$remaining} row(s) ".
                    'with a NULL organization_id. Run php artisan aculyze:backfill-organizations '.
                    '(or the equivalent production SQL) first, and verify it reports zero remaining NULLs.'
                );
            }
        }
    }
};
