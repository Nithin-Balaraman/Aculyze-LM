<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per user, storing which toggleable columns they have visible on
 * the Prospects table — Filament's own built-in toggle-column persistence
 * (Concerns\CanToggleColumns::updatedToggledTableColumns()) writes only to
 * the PHP session, which does not survive logout (session invalidated) or
 * a different browser/device — both real scenarios given this app's
 * 3-tier hierarchy. This is DB-backed, per-user instead.
 *
 * Deliberately scoped to exactly this one table's toggle state (a single
 * JSON column keyed by user_id), not a generic "user preferences"
 * framework — nothing equivalent already existed to extend (checked:
 * no settings table, no JSON preference column on users), and building a
 * multi-table/multi-purpose store isn't what was asked for.
 *
 * No organization_id/OrganizationScope — this is a personal UI
 * preference, not organization-owned business data (the same treatment
 * `users` itself gets: see User's own class docblock).
 *
 * cascadeOnDelete() here — unlike every other FK in this schema, which is
 * RESTRICT (AGENTS.md section 59: "no cascading deletes on business
 * history"). That rule protects real sales history; a saved column-toggle
 * preference is neither business history nor evidence of anything, so it
 * would be actively wrong to let it block Employee offboarding the way a
 * real ownership row correctly does — it should just quietly disappear
 * with the user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospect_table_column_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->json('toggled_columns');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospect_table_column_preferences');
    }
};
