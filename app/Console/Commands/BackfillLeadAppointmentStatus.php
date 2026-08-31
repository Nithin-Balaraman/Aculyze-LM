<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Enums\LeadStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 2: backfills the normalized `leads.status`/`appointments.status`
 * from the legacy `stage` column. The mapping itself lives in exactly one
 * place — LeadStatus::fromLegacyStage()/AppointmentStatus::fromLegacyStage()
 * — so this command and any other code path that needs to derive a status
 * from a legacy stage (e.g. PipelineBoard's cross-drop destination
 * creation) can never diverge onto two different tables.
 *
 * Idempotent (WHERE status IS NULL guarded) and non-destructive (`stage`
 * is never modified). Hard-fails — leaving every row untouched — if any
 * row's `stage` value falls outside the enum's known set, rather than
 * silently defaulting it.
 *
 * This is the local/development/future-environment equivalent of the
 * manual Hostinger production SQL runbook addition for Phase 2 (which has
 * no Artisan/SSH access) — both must stay logically identical.
 */
class BackfillLeadAppointmentStatus extends Command
{
    protected $signature = 'aculyze:backfill-lead-appointment-status';

    protected $description = 'Backfill Lead.status/Appointment.status from the legacy stage column using the approved conservative mapping';

    public function handle(): int
    {
        $this->assertNoUnknownLegacyValues('leads', fn (string $stage) => LeadStatus::fromLegacyStage($stage));
        $this->assertNoUnknownLegacyValues('appointments', fn (string $stage) => AppointmentStatus::fromLegacyStage($stage));

        foreach ($this->distinctStages('leads') as $legacyStage) {
            $status = LeadStatus::fromLegacyStage($legacyStage);

            $updated = DB::table('leads')
                ->where('stage', $legacyStage)
                ->whereNull('status')
                ->update(['status' => $status->value]);

            $this->info("leads: {$legacyStage} -> {$status->value} ({$updated} row(s)).");
        }

        foreach ($this->distinctStages('appointments') as $legacyStage) {
            $status = AppointmentStatus::fromLegacyStage($legacyStage);

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
     * @return array<int, string>
     */
    private function distinctStages(string $table): array
    {
        return DB::table($table)->whereNotNull('stage')->whereNull('status')->distinct()->pluck('stage')->all();
    }

    private function assertNoUnknownLegacyValues(string $table, \Closure $mapper): void
    {
        $unknown = [];

        foreach ($this->distinctStages($table) as $stage) {
            try {
                $mapper($stage);
            } catch (\ValueError) {
                $unknown[] = $stage;
            }
        }

        if ($unknown !== []) {
            throw new RuntimeException(
                "Refusing to backfill {$table}.status: found unrecognized legacy stage value(s) [".
                implode(', ', $unknown)."] with no known mapping. No row in {$table} was changed. ".
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
