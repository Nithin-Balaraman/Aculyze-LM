<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive step 1 of the nullable -> backfill -> verify -> NOT NULL
 * sequence (Phase 1 plan). organization_id is added nullable here on
 * every existing organization-owned table so nothing existing breaks;
 * see 2026_08_30_090300_backfill_organization_id_columns.php for the
 * backfill and 2026_08_30_090400_make_organization_id_not_null.php for
 * the guarded tightening step. No foreign-key constraint is added yet
 * (deferred to the tightening migration, once every row is known-good —
 * adding it now would risk a constraint error against not-yet-backfilled
 * NULL values for no benefit at this stage).
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
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('organization_id')->nullable()->after('id');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('organization_id');
            });
        }
    }
};
