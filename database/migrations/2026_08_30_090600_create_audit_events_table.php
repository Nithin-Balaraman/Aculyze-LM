<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Phase 1 audit-trail foundation. Generic entity_type/entity_id/action
 * shape (not a fixed enum of business actions) so a future action — e.g. a
 * Lead-Lost reopen/correction (explicitly on hold, not implemented yet) —
 * needs only a new `action` string value to log against, never a schema
 * change.
 *
 * organization_id is nullable, paired with `scope` ('organization' vs
 * 'system') — Phase 1's Tenancy::withoutScopeForSystemTask() bypass can
 * represent a genuinely cross-organization/system-level action, and no
 * organization is ever guessed just to satisfy a NOT NULL column for one.
 * App\Support\Audit\AuditLogger enforces the pairing invariant (organization
 * scope requires an id; system scope requires none) at the application
 * layer. A NULL organization_id also means such rows never match any
 * per-organization query once App\Models\Scopes\OrganizationScope is
 * attached to this table (added in a later migration, after this table and
 * its model exist) — they are structurally invisible to any normal
 * organization-scoped audit view, not merely policy-hidden.
 *
 * No updated_at — audit rows are immutable through normal application use
 * (see App\Models\AuditEvent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->string('scope')->default('organization');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role_snapshot')->nullable();
            $table->string('actor_name_snapshot')->nullable();
            $table->string('actor_email_snapshot')->nullable();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('action');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'entity_type', 'entity_id']);
            $table->index(['organization_id', 'created_at']);
            $table->index('actor_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
