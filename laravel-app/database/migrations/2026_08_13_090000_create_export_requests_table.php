<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import Access + Export Approval batch, Section 2.2: one shared table for
 * every employee-initiated export request across FollowUp/Lead/
 * Appointment/Proposal, rather than one table per resource — the approval
 * lifecycle (pending -> approved/denied, expiry) is identical regardless of
 * which resource is being exported.
 *
 * user_id/decided_by use cascade/null-on-delete (unlike the rest of this
 * app's FKs, which are plain RESTRICT — see App\Support\DeletionGuard) on
 * purpose: an ExportRequest is a lightweight, disposable download request,
 * not durable business history like a Prospect/Lead/Proposal, so it has no
 * reason to block deleting the employee who made it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('resource');

            // Normalized, whitelisted filter values only (e.g. {"stage":
            // "validated"} or {"scope": "pending"}) — never raw SQL or
            // arbitrary query-builder state. See the per-resource Exporter
            // classes in App\Support\Exports for what each resource accepts.
            $table->json('filters')->nullable();

            $table->string('status')->default('pending');

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('denial_reason')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_requests');
    }
};
