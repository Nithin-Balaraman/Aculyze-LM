<?php

namespace Tests\Feature;

use App\Enums\AppointmentStage;
use App\Enums\AppointmentStatus;
use App\Enums\FollowUpStatus;
use App\Filament\Pages\PipelineBoard;
use App\Models\Appointment;
use App\Models\FollowUp;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Services\RescheduleService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Verification round: the Phase 2 completion report claimed "PipelineBoard
 * required zero changes", reasoning only from the Follow-up lane's fixed
 * 3-key status map. That claim was WRONG for Appointment — its lane groups
 * by the untouched legacy `stage` alone (App\Enums\AppointmentStage), which
 * RescheduleService/WorkflowTransitionService never touch, so a
 * Rescheduled/repeat-activity-Completed Appointment kept showing as an
 * active card under whatever stage box it happened to already occupy.
 * Fixed via Appointment::scopeExcludingHistoricalStatus() (see
 * PipelineBoard::appointmentLane() and AppointmentResource\Pages\
 * ListAppointments's Pending/History tabs).
 *
 * This test drives the REAL PipelineBoard Livewire component
 * (Livewire::test()->instance()->getLanes()) rather than a helper
 * disconnected from the board, so it exercises the exact same query path
 * a browser session renders.
 */
class PipelineBoardVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function boardLanes(): array
    {
        return Livewire::test(PipelineBoard::class)->instance()->getLanes();
    }

    public function test_rescheduled_appointment_is_excluded_from_its_active_stage_box_and_replacement_is_shown(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

            $original = Appointment::create([
                'prospect_id' => $prospect->id,
                'assigned_to' => $user->id,
                'created_by' => $user->id,
                'appointment_at' => now()->addDays(2),
                'stage' => AppointmentStage::AppointmentMade,
                'status' => AppointmentStatus::Scheduled,
            ]);

            $replacement = app(RescheduleService::class)->reschedule($original, ['appointment_at' => now()->addDays(5)]);

            $cardIds = collect($this->boardLanes()['appointment']['stages']['appointment_made']['cards'])->pluck('id');

            $this->assertNotContains($original->id, $cardIds, 'Rescheduled Appointment must not appear as an active card.');
            $this->assertContains($replacement->id, $cardIds, 'The replacement Appointment must appear as the active card.');

            // legacy stage unchanged, historical record still exists
            $this->assertSame(AppointmentStage::AppointmentMade, $original->fresh()->stage);
            $this->assertDatabaseHas('appointments', ['id' => $original->id, 'status' => 'rescheduled']);

            // no duplicate active cards for the same underlying activity
            $this->assertCount(1, $cardIds->filter(fn ($id) => in_array($id, [$original->id, $replacement->id], true)));
        });
    }

    public function test_completed_via_repeat_activity_appointment_is_excluded_from_its_stage_box(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

            $original = Appointment::create([
                'prospect_id' => $prospect->id,
                'assigned_to' => $user->id,
                'created_by' => $user->id,
                'appointment_at' => now()->addDays(2),
                'stage' => AppointmentStage::AppointmentMade,
                'status' => AppointmentStatus::Scheduled,
            ]);

            app(\App\Services\WorkflowTransitionService::class)->transitionAppointmentOutcome(
                $original, \App\Enums\AppointmentOutcome::AnotherAppointmentRequired, [
                    'appointment_at' => now()->addDays(3),
                    'outcome_notes' => 'Needs a second visit to finalize.',
                ]
            );

            $cardIds = collect($this->boardLanes()['appointment']['stages']['appointment_made']['cards'])->pluck('id');

            $this->assertNotContains($original->id, $cardIds, 'A Completed-via-outcome Appointment (stage unchanged) must not appear as an active card.');
            $this->assertSame(AppointmentStage::AppointmentMade, $original->fresh()->stage);
        });
    }

    public function test_rescheduled_follow_up_is_excluded_from_the_pending_box_and_replacement_is_shown(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

            $original = FollowUp::create([
                'prospect_id' => $prospect->id,
                'user_id' => $user->id,
                'follow_up_at' => now()->addDay(),
                'reason' => 'Callback requested',
                'status' => FollowUpStatus::Pending,
            ]);

            $replacement = app(RescheduleService::class)->reschedule($original, ['follow_up_at' => now()->addDays(3)]);

            $pendingCardIds = collect($this->boardLanes()['follow_up']['stages']['pending']['cards'])->pluck('id');

            $this->assertNotContains($original->id, $pendingCardIds, 'Rescheduled Follow-Up must not appear as an active Pending card.');
            $this->assertContains($replacement->id, $pendingCardIds, 'The replacement Follow-Up must appear as the active Pending card.');

            // The rescheduled status value doesn't even have a stage box
            // in the Follow-up lane's fixed 3-key map (Pending/Completed/
            // Cancelled) — confirm it doesn't silently appear under either
            // of the other two boxes either.
            $completedCardIds = collect($this->boardLanes()['follow_up']['stages']['completed']['cards'])->pluck('id');
            $cancelledCardIds = collect($this->boardLanes()['follow_up']['stages']['cancelled']['cards'])->pluck('id');
            $this->assertNotContains($original->id, $completedCardIds);
            $this->assertNotContains($original->id, $cancelledCardIds);

            $this->assertDatabaseHas('follow_ups', ['id' => $original->id, 'status' => 'rescheduled']);
        });
    }

    public function test_pipeline_board_still_renders_the_five_lanes_with_no_error(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);

            $lanes = $this->boardLanes();

            $this->assertSame(['call', 'follow_up', 'appointment', 'lead', 'proposal'], array_keys($lanes));
        });
    }
}
