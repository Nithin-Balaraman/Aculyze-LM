<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4A-1: commercial line rows belong to a ProposalVersion, never
 * directly to a Proposal — Master BA Specification section 3.3's own
 * explicit structural rule: "A direct proposal_lines -> proposal_id design
 * is explicitly rejected because it would break version history." Every
 * revision (a later sub-phase) clones the prior version's lines into
 * entirely new rows here; rows are never shared or mutated across
 * versions.
 *
 * No product/service reference column: this repository has no Product
 * catalog model at all (confirmed during the Phase 4A technical
 * preflight), so every line is a free-text snapshot rather than a
 * relationship to a catalog item.
 *
 * cascadeOnDelete() on proposal_version_id (unlike proposal_versions' OWN
 * restrictOnDelete() on proposal_id): nothing in Phase 4A-1 provides any
 * way to delete a ProposalVersion, so this is purely defensive
 * referential cleanup for a case this phase doesn't create a path to, not
 * a historical-integrity concession — see the Phase 4A-1 completion
 * report for the full reasoning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_version_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('proposal_version_id')->constrained('proposal_versions')->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->string('item_name')->nullable();
            $table->text('description')->nullable();
            $table->string('hsn_sac')->nullable();
            $table->decimal('quantity', 18, 4);
            $table->string('unit')->nullable();
            $table->decimal('unit_price', 18, 2);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 9, 4)->nullable();
            $table->decimal('discount_amount', 18, 2)->nullable();
            $table->decimal('gross_amount', 18, 2)->nullable();
            $table->decimal('taxable_amount', 18, 2)->nullable();
            $table->decimal('tax_amount', 18, 2)->nullable();
            $table->decimal('line_total', 18, 2)->nullable();
            $table->timestamps();

            $table->unique(['proposal_version_id', 'line_number']);
            $table->index(['organization_id', 'proposal_version_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_version_lines');
    }
};
