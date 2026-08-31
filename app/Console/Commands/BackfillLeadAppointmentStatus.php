<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Enums\LeadStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 2: backfills the normalized `leads.status`/`appointments.status`
 * from the legacy `stage` column, using the EXACT conservative mapping
 * approved for the Phase 2 plan — never a guess, never inferring more
 * than the legacy value actually recorded:
 *
 *   Lead.stage                          -> Lead.status
 *   requirement_collection               -> requirement_collection
 *   validated                            -> requirement_confirmed
 *   demo_scheduled_or_done                -> requirement_confirmed
 *     (DemoScheduledOrDone cannot safely map to DemoRequired — the legacy
 *     value means a Demo may already have been scheduled OR completed;
 *     RequirementConfirmed is the safe normalized interpretation. No Demo
 *     row is ever fabricated here, and ProposalRequired is never inferred
 *     merely because an old Proposal may already exist.)
 *
 *   Appointment.stage                    -> Appointment.status  (outcome always left NULL)
 *   appointment_made                     -> scheduled
 *   visit_conducted                      -> completed
 *   discussion_completed                 -> completed
 *   succeeded                            -> completed
 *   not_succeeded                        -> completed
 *     (Succeeded does NOT imply Requirement Identified/Proposal Required;
 *     NotSucceeded does NOT imply No Current Requirement — the legacy data
 *     never captured a structured outcome, so none is fabricated.)
 *
 * Idempotent (WHERE status IS NULL guarded) and non-destructive (`stage`
 * is never modified). Hard-fails — leaving every row untouched — if any
 * row's `stage` value falls outside the exact known set above, rather
 * than silently defaulting it; see report() for how to find such rows.
 *
 * This is the local/development/future-environment equivalent of the
 * manual Hostinger production SQL runbook addition for Phase 2 (which has
 * no Artisan/SSH access) — both must stay logically identical.
 */
class BackfillLeadAppointmentStatus extends Command
{
    protected $signature = 'aculyze:backfill-lead-appointment-status';

    protected $description = 'Backfill Lead.status/Appointment.status from the legacy stage column using the approved conservative mapping';

    private const LEAD_MAP = [
        'requirement_collection' => LeadStatus::RequirementCollection,
        'validated' => LeadStatus::RequirementConfirmed,
        'demo_scheduled_or_done' => LeadStatus::RequirementConfirmed,
    ];

    private const APPOINTMENT_MAP = [
        'appointment_made' => AppointmentStatus::Scheduled,
        'visit_conducted' => AppointmentStatus::Completed,
        'discussion_completed' => AppointmentStatus::Completed,
        'succeeded' => AppointmentStatus::Completed,
        'not_succeeded' => AppointmentStatus::Completed,
    ];

    public function handle(): int
    {
        $this->assertNoUnknownLegacyValues('leads', self::LEAD_MAP);
        $this->assertNoUnknownLegacyValues('appointments', self::APPOINTMENT_MAP);

        foreach (self::LEAD_MAP as $legacyStage => $status) {
            $updated = DB::table('leads')
                ->where('stage', $legacyStage)
                ->whereNull('status')
                ->update(['status' => $status->value]);

            $this->info("leads: {$legacyStage} -> {$status->value} ({$updated} row(s)).");
        }

        foreach (self::APPOINTMENT_MAP as $legacyStage => $status) {
            $updated = DB::table('appointments')
                ->where('stage', $legacyStage)
                ->whereNull('status')
                ->update(['status' => $status->value]);

            $this->info("appointments: {$legacyStage} -> {$status->value} ({$updated} row(s)), outcome left NULL.");
        }

        $this->verify();

        return self::SUCCESS;
    }

    /**
     * @param  array<string, \BackedEnum>  $map
     */
    private function assertNoUnknownLegacyValues(string $table, array $map): void
    {
        $known = array_keys($map);

        $unknown = DB::table($table)
            ->whereNotNull('stage')
            ->whereNotIn('stage', $known)
            ->whereNull('status')
            ->distinct()
            ->pluck('stage');

        if ($unknown->isNotEmpty()) {
            throw new RuntimeException(
                "Refusing to backfill {$table}.status: found unrecognized legacy stage value(s) [".
                $unknown->implode(', ')."] with no known mapping. No row in {$table} was changed. ".
                'Resolve the mapping for these values explicitly before re-running this command.'
            );
        }
    }

    private function verify(): void
    {
        $problems = [];

        foreach (['leads', 'appointments'] as $table) {
            $remaining = DB::table($table)->whereNull('status')->count();

            if ($remaining > 0) {
                $problems[] = "{$table}: {$remaining} row(s) still have a NULL status";
            }
        }

        if ($problems !== []) {
            $this->error('Backfill verification failed:');

            foreach ($problems as $problem) {
                $this->error(" - {$problem}");
            }

            return;
        }

        $this->info('Verification passed: every applicable table has zero NULL status rows.');
    }
}
