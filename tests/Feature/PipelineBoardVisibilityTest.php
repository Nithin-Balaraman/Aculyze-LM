<?php

namespace Tests\Feature;

use App\Enums\AppointmentStage;
use App\Enums\AppointmentStatus;
use App\Enums\FollowUpStatus;
use App\Filament\Pages\PipelineBoard;
use App\Models\Appointment;
use App\Models\Demo;
use App\Models\FollowUp;
use App\Models\Lead;
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

    /**
     * Pipeline Board visual redesign: getLanes() now returns one flat
     * `cards` list per lane (no nested per-stage grouping) — the record's
     * own stage/status is a `stageValue` field on the card itself instead.
     * This reproduces the old per-stage-box lookup on top of the new flat
     * shape, so every assertion below keeps its original intent.
     */
    private function cardIdsAtStage(array $lanes, string $laneKey, string $stageValue): \Illuminate\Support\Collection
    {
        return collect($lanes[$laneKey]['cards'])
            ->where('stageValue', $stageValue)
            ->pluck('id');
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

            $cardIds = $this->cardIdsAtStage($this->boardLanes(), 'appointment', 'appointment_made');

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

            $cardIds = $this->cardIdsAtStage($this->boardLanes(), 'appointment', 'appointment_made');

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

            $lanes = $this->boardLanes();
            $pendingCardIds = $this->cardIdsAtStage($lanes, 'follow_up', 'pending');

            $this->assertNotContains($original->id, $pendingCardIds, 'Rescheduled Follow-Up must not appear as an active Pending card.');
            $this->assertContains($replacement->id, $pendingCardIds, 'The replacement Follow-Up must appear as the active Pending card.');

            // The rescheduled status value doesn't map to any of the
            // Follow-up lane's own 3 real statuses (Pending/Completed/
            // Cancelled) — confirm it doesn't silently appear tagged as
            // either of the other two either.
            $completedCardIds = $this->cardIdsAtStage($lanes, 'follow_up', 'completed');
            $cancelledCardIds = $this->cardIdsAtStage($lanes, 'follow_up', 'cancelled');
            $this->assertNotContains($original->id, $completedCardIds);
            $this->assertNotContains($original->id, $cancelledCardIds);

            $this->assertDatabaseHas('follow_ups', ['id' => $original->id, 'status' => 'rescheduled']);
        });
    }

    public function test_pipeline_board_still_renders_the_six_lanes_with_no_error(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);

            $lanes = $this->boardLanes();

            $this->assertSame(['call', 'follow_up', 'appointment', 'lead', 'demo', 'proposal'], array_keys($lanes));
        });
    }

    /**
     * Phase 3: Demo has no legacy stage — its lane groups purely by
     * normalized DemoStatus (mirroring the Follow-up lane's shape, not the
     * legacy stage-based lanes), so a Rescheduled Demo must be excluded from
     * the active Scheduled box exactly like a Rescheduled Appointment/
     * Follow-Up already is.
     */
    public function test_demo_lane_groups_by_normalized_status_and_excludes_rescheduled_from_the_active_box(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $lead = Lead::create([
                'prospect_id' => $prospect->id,
                'assigned_to' => $user->id,
                'created_by' => $user->id,
                'stage' => 'requirement_collection',
                'status' => \App\Enums\LeadStatus::RequirementCollection,
                'temperature' => 'warm',
            ]);

            $active = Demo::create([
                'prospect_id' => $prospect->id,
                'lead_id' => $lead->id,
                'assigned_to' => $user->id,
                'created_by' => $user->id,
                'demo_at' => now()->addDays(2),
                'mode' => \App\Enums\DemoMode::Online,
                'meeting_link' => 'https://meet.example.com/a',
                'status' => \App\Enums\DemoStatus::Scheduled,
            ]);

            $rescheduledFrom = app(RescheduleService::class)->reschedule($active, ['demo_at' => now()->addDays(5)]);

            $lanes = $this->boardLanes();
            $scheduledCardIds = $this->cardIdsAtStage($lanes, 'demo', 'scheduled');
            $rescheduledCardIds = $this->cardIdsAtStage($lanes, 'demo', 'rescheduled');

            $this->assertNotContains($active->id, $scheduledCardIds, 'Rescheduled Demo must not appear as an active Scheduled card.');
            $this->assertContains($rescheduledFrom->id, $scheduledCardIds, 'The replacement Demo must appear as the active Scheduled card.');
            $this->assertContains($active->id, $rescheduledCardIds);
        });
    }
}
