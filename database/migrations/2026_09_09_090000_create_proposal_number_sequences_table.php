<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4A-3.1 (locked Decision 1): the safe concurrent-numbering mechanism
 * behind App\Services\ProposalNumberService — one counter row per
 * (organization_id, year), incremented under a row lock. This exists
 * specifically because locking a Proposal/ProposalVersion row (already done
 * by ProposalVersionWorkflowService::approve()) does NOT serialize number
 * allocation across DIFFERENT Proposals in the same organization — a
 * dedicated counter row is the only safe way to avoid MAX()+1.
 *
 * organization_id is restrictOnDelete — same convention as every other
 * organization-scoped table in this schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_number_sequences');
    }
};
