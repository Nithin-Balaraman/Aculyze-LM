<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: the final, self-guarding step of the nullable -> backfill ->
 * verify -> NOT NULL sequence for `leads.status`/`appointments.status`,
 * mirroring 2026_08_30_090400_make_organization_id_not_null.php's shape.
 * Refuses to run — throwing before touching the schema — unless every row
 * already has a non-NULL `status`; on a real upgrade, run
 * php artisan aculyze:backfill-lead-appointment-status (or the equivalent
 * Hostinger production SQL) first.
 *
 * Unlike organization_id's tightening (which has no DB-level default —
 * every insert path was already required to supply it), `status` is given
 * a DB-level DEFAULT here: LeadStatus::RequirementCollection / AppointmentStatus::Scheduled — their
 * respective initial values. This is deliberate: `stage`/`AppointmentStage`
 * remain the columns every pre-Phase-2 create path (including the large
 * existing test suite) already populates, and none of that code is being
 * rewritten just to also pass `status` explicitly. New Phase 2 code
 * (App\Services\CallRoutingService, RescheduleService,
 * WorkflowTransitionService) always sets `status` explicitly regardless;
 * the default only ever satisfies an older/untouched call site, never
 * masks a Phase 2 code path forgetting to set it.
 *
 * `outcome` is deliberately NOT tightened here or ever — not every Lead/
 * Appointment has a recorded outcome, and it never will for every row.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertBackfillComplete();

        Schema::table('leads', function (Blueprint $table) {
            $table->string('status')->default(\App\Enums\LeadStatus::RequirementCollection->value)->nullable(false)->change();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->string('status')->default(\App\Enums\AppointmentStatus::Scheduled->value)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('status')->nullable()->change();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->string('status')->nullable()->change();
        });
    }

    private function assertBackfillComplete(): void
    {
        $problems = [];

        foreach (['leads', 'appointments'] as $table) {
            $remaining = DB::table($table)->whereNull('status')->count();

            if ($remaining > 0) {
                $problems[] = "{$table} still has {$remaining} row(s) with a NULL status";
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(
                'Refusing to tighten status to NOT NULL: '.implode('; ', $problems).
                '. Run php artisan aculyze:backfill-lead-appointment-status '.
                '(or the equivalent production SQL) first, and verify it reports zero remaining NULLs.'
            );
        }
    }
};
