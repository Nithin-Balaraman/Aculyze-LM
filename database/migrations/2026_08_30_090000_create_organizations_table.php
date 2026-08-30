<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization is the tenant boundary introduced in Phase 1 of the BA/
 * Implementation Specification foundation work. Aculyze Solutions is the
 * initial (and, for now, only) organization — every existing business
 * record is backfilled onto it in a later migration
 * (2026_08_30_090300_backfill_organization_id_columns.php), never guessed
 * or defaulted implicitly at the database layer.
 *
 * `settings` is included directly in this table from the start (a JSON
 * placeholder for future module/feature-flag and working-hours/reminder
 * configuration) rather than being added via a later ALTER TABLE, since
 * there is no existing data to risk — adding it here avoids ever creating
 * the column twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('timezone')->default('Asia/Kolkata');
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
