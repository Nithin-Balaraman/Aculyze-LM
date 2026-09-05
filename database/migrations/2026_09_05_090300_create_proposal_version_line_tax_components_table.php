<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4A-1: one-or-more tax component snapshots per line (Master BA
 * Specification sections 3.3/6.3) — deliberately never a single tax_id on
 * the line itself, so CGST+SGST (or IGST alone) can both be represented.
 *
 * cascadeOnDelete() on proposal_version_line_id — same reasoning as
 * proposal_version_lines' own FK to proposal_versions: nothing in Phase
 * 4A-1 deletes a line, this is defensive referential cleanup only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_version_line_tax_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnUpdate()->restrictOnDelete();
            // Explicit short FK name: the default
            // "proposal_version_line_tax_components_proposal_version_line_id_foreign"
            // exceeds MySQL/MariaDB's 64-character identifier limit.
            $table->foreignId('proposal_version_line_id')
                ->constrained('proposal_version_lines', indexName: 'pvltc_proposal_version_line_id_foreign')
                ->cascadeOnDelete();
            $table->string('component_type');
            $table->decimal('rate', 9, 4);
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            // Explicit short index name: the default composite index name
            // exceeds MySQL/MariaDB's 64-character identifier limit, same
            // as the FK above.
            $table->index(['organization_id', 'proposal_version_line_id'], 'pvltc_organization_id_line_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_version_line_tax_components');
    }
};
