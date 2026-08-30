<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Employee/Manager/Senior Manager hierarchy edge (Phase 1). Unlike
 * organization_id, this needs no nullable -> backfill -> tighten dance:
 * every existing row starts NULL (nobody currently has a manager
 * relationship recorded) and stays nullable indefinitely — a Senior
 * Manager legitimately has none, and assigning who reports to whom is a
 * deliberate, reviewed, post-deployment configuration step (see the
 * Phase 1 completion report), never guessed or auto-assigned here. The
 * foreign key can be added immediately alongside the column since there
 * is no legacy data to reconcile.
 *
 * restrictOnDelete (not nullOnDelete/cascade) so a Manager with existing
 * direct reports cannot simply be deleted out from under them — mirrors
 * every other ownership FK in this schema; App\Services\
 * EmployeeDeletionService is extended to surface "has direct reports" as
 * a first-class dependency requiring reassignment first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('manager_id')
                ->nullable()
                ->after('role')
                ->constrained('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['manager_id']);
            $table->dropColumn('manager_id');
        });
    }
};
