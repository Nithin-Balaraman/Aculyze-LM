<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: Demo domain/model foundation only — no Filament Resource, no
 * Pipeline Board lane (deferred to Phase 3). A Lead hasMany Demos (no
 * unique constraint on lead_id — "Another Demo Required" and an explicit
 * Reschedule both create additional Demo rows against the same Lead, never
 * a new Lead).
 *
 * Deliberately has NO `follow_up_at` column: when a Demo's outcome is
 * "More Time / Discussion", App\Services\WorkflowTransitionService creates
 * a real App\Models\FollowUp record, which is the sole source of truth for
 * that future schedule — never duplicated here.
 *
 * `rescheduled_from_id` (single reschedule-linkage column — see
 * Demo::replacedBy()) is a different concept from `origin_type`/
 * `origin_id` (which prior activity — Follow-Up, Appointment, Lead,
 * Proposal, or another completed Demo — caused this Demo to be created).
 * Both use restrictOnDelete/no-FK-on-morph respectively; never confused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('rescheduled_from_id')->nullable()->constrained('demos')->restrictOnDelete();
            $table->nullableMorphs('origin');
            $table->foreignId('prospect_id')->constrained('prospects')->restrictOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->restrictOnDelete();
            $table->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('demo_at');
            $table->string('mode');
            $table->string('location')->nullable();
            $table->string('meeting_link')->nullable();
            $table->json('attendees')->nullable();
            $table->string('product_service')->nullable();
            $table->text('purpose')->nullable();
            $table->text('feedback')->nullable();
            $table->text('correction_comments')->nullable();
            $table->text('notes')->nullable();
            $table->string('status');
            $table->string('outcome')->nullable();
            $table->string('next_action')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'lead_id']);
            $table->index(['organization_id', 'status']);
            $table->index('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demos');
    }
};
