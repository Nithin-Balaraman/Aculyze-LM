<?php

namespace Tests\Feature;

use App\Enums\AppointmentOutcome;
use App\Enums\AppointmentStage;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Services\RescheduleService;
use App\Services\WorkflowTransitionService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Found while investigating the Pipeline Board reschedule-visibility
 * defect (see PipelineBoardVisibilityTest): AppointmentResource's own List
 * page "Pending" tab had the identical gap — it filtered only on
 * `is_lost`/legacy `stage`, never the new `status` column, so a
 * Rescheduled or repeat-activity-Completed Appointment (whose stage is
 * untouched) kept appearing in the normal Pending queue. Fixed via the
 * same Appointment::scopeExcludingHistoricalStatus()/scopeHistoricalStatus()
 * pair used by the Pipeline Board.
 */
class AppointmentListVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function newAppointment(User $user): Appointment
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

        return Appointment::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $user->id,
            'created_by' => $user->id,
            'appointment_at' => now()->addDays(2),
            'stage' => AppointmentStage::AppointmentMade,
            'status' => AppointmentStatus::Scheduled,
        ]);
    }

    public function test_rescheduled_appointment_is_excluded_from_the_pending_query_and_included_in_history(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $original = $this->newAppointment($user);
            $replacement = app(RescheduleService::class)->reschedule($original, ['appointment_at' => now()->addDays(4)]);

            $pendingIds = Appointment::query()->visibleTo($user)
                ->where('is_lost', false)
                ->whereNotIn('stage', ['succeeded', 'not_succeeded'])
                ->excludingHistoricalStatus()
                ->pluck('id');

            $this->assertNotContains($original->id, $pendingIds);
            $this->assertContains($replacement->id, $pendingIds);

            $historyIds = Appointment::query()->visibleTo($user)
                ->where(fn ($q) => $q->where('is_lost', true)->orWhereIn('stage', ['succeeded', 'not_succeeded'])->orWhere->historicalStatus())
                ->pluck('id');

            $this->assertContains($original->id, $historyIds);
        });
    }

    public function test_completed_via_repeat_activity_appointment_is_excluded_from_the_pending_query(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $original = $this->newAppointment($user);

            app(WorkflowTransitionService::class)->transitionAppointmentOutcome(
                $original, AppointmentOutcome::AnotherAppointmentRequired, ['appointment_at' => now()->addDays(3)]
            );

            $pendingIds = Appointment::query()->visibleTo($user)
                ->where('is_lost', false)
                ->whereNotIn('stage', ['succeeded', 'not_succeeded'])
                ->excludingHistoricalStatus()
                ->pluck('id');

            $this->assertNotContains($original->id, $pendingIds);
        });
    }
}
