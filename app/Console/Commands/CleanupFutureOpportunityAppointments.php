<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStage;
use App\Enums\CallOutcome;
use App\Models\Appointment;
use Illuminate\Console\Command;

/**
 * One-off cleanup for the Future Opportunity routing bug (see
 * App\Enums\CallOutcome::routesToAppointment()): before the fix, selecting
 * "No Current Requirement / Future Opportunity" incorrectly created an
 * Appointment. This finds every Appointment created that way and, only when
 * --force is passed, deletes it — the originating Call Record is never
 * touched, only the wrongly-created Appointment.
 *
 * Defaults to a dry run: without --force it only lists what it would
 * delete, so this can be reviewed before anything is removed.
 */
class CleanupFutureOpportunityAppointments extends Command
{
    protected $signature = 'call-records:cleanup-future-opportunity-appointments {--force : Actually delete the Appointments instead of only listing them}';

    protected $description = 'List (or, with --force, delete) Appointments incorrectly auto-created from a Future Opportunity call outcome';

    public function handle(): int
    {
        $appointments = Appointment::query()
            ->whereHas('callRecord', fn ($query) => $query->where('outcome', CallOutcome::FutureOpportunity))
            ->with(['callRecord.prospect', 'assignedEmployee'])
            ->get();

        if ($appointments->isEmpty()) {
            $this->info('No Appointments were incorrectly created from a Future Opportunity call outcome. Nothing to do.');

            return self::SUCCESS;
        }

        $this->table(
            ['Appointment ID', 'Company', 'Stage', 'Assigned To', 'Call Record ID', 'Called At'],
            $appointments->map(fn (Appointment $appointment) => [
                $appointment->id,
                $appointment->callRecord?->prospect?->company_name ?? '(deleted prospect)',
                $appointment->stage->getLabel(),
                $appointment->assignedEmployee?->name ?? '(unassigned)',
                $appointment->call_record_id,
                $appointment->callRecord?->called_at?->format('Y-m-d H:i'),
            ]),
        );

        $advanced = $appointments->filter(
            fn (Appointment $appointment) => $appointment->stage !== AppointmentStage::AppointmentMade || $appointment->is_lost
        );

        if ($advanced->isNotEmpty()) {
            $this->warn(
                "{$advanced->count()} of these have moved past their initial 'Appointment Made' stage, or been marked Lost, since being wrongly created — review them before deleting."
            );
        }

        if (! $this->option('force')) {
            $this->line('');
            $this->comment(
                'Dry run — no records were deleted. Re-run with --force to delete the '.$appointments->count()
                .' Appointment(s) listed above. The originating Call Records are never touched.'
            );

            return self::SUCCESS;
        }

        $deleted = Appointment::destroy($appointments->pluck('id'));
        $this->info("Deleted {$deleted} Appointment(s). The originating Call Records were left untouched.");

        return self::SUCCESS;
    }
}
