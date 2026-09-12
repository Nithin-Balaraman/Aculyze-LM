<?php

namespace Tests\Feature;

use App\Enums\AppointmentStage;
use App\Filament\Pages\PipelineBoard;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pipeline Board redesign, Section 17 Milestone 5 "stale record handling":
 * a card's underlying record can legitimately vanish between the board being
 * rendered (client-side) and the drag being confirmed (server-side round
 * trip) — e.g. another rep deleted the Lead, or (for a same-lane drag) it
 * already reached the destination stage via some other action in between.
 * resolveDropRecord() returns null via find() rather than findOrFail(), and
 * every performDrop()/performCrossDrop() branch guards on that null (or on
 * "already at this stage") before doing anything — this locks that guard
 * down as a real regression test rather than something only visible by
 * reading the code, and confirms the failure is a silent, safe no-op, never
 * a raw exception surfaced to the rep.
 */
class PipelineBoardStaleRecordSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function invokePerformDrop(PipelineBoard $board, array $arguments, array $data = []): void
    {
        $method = new \ReflectionMethod($board, 'performDrop');
        $method->setAccessible(true);
        $method->invoke($board, $arguments, $data);
    }

    private function invokePerformCrossDrop(PipelineBoard $board, array $arguments, array $data = []): void
    {
        $method = new \ReflectionMethod($board, 'performCrossDrop');
        $method->setAccessible(true);
        $method->invoke($board, $arguments, $data);
    }

    public function test_dropping_a_same_lane_card_that_was_deleted_before_the_drag_was_confirmed_is_a_safe_no_op(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $appointment = Appointment::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'appointment_at' => now()->addDay(), 'stage' => 'appointment_made',
            ]);
            $deletedId = $appointment->id;
            $appointment->delete();

            $this->invokePerformDrop(
                app(PipelineBoard::class),
                ['resource' => 'appointment', 'id' => $deletedId, 'stage' => AppointmentStage::VisitConducted->value],
                ['meeting_notes' => 'Some notes.'],
            );

            $this->assertDatabaseCount('appointments', 0);
        });
    }

    public function test_dropping_a_card_already_moved_to_the_target_stage_by_someone_else_is_a_safe_no_op(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $appointment = Appointment::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'appointment_at' => now()->addDay(), 'stage' => AppointmentStage::VisitConducted->value,
                'meeting_notes' => 'Already conducted by someone else.',
            ]);

            $this->invokePerformDrop(
                app(PipelineBoard::class),
                ['resource' => 'appointment', 'id' => $appointment->id, 'stage' => AppointmentStage::VisitConducted->value],
                ['meeting_notes' => 'Overwritten notes.'],
            );

            $appointment->refresh();
            $this->assertSame('Already conducted by someone else.', $appointment->meeting_notes, 'A drag onto the stage the card already sits in must never overwrite its data.');
        });
    }

    public function test_cross_dropping_a_source_card_that_was_deleted_before_the_drag_was_confirmed_is_a_safe_no_op(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $lead = Lead::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'stage' => 'requirement_collection', 'temperature' => 'hot', 'notes' => 'x',
            ]);
            $deletedId = $lead->id;
            $lead->delete();

            $this->invokePerformCrossDrop(
                app(PipelineBoard::class),
                ['sourceResource' => 'lead', 'sourceId' => $deletedId, 'destResource' => 'appointment', 'destStage' => 'appointment_made'],
                ['destination_appointment_at' => now()->addDay()],
            );

            $this->assertDatabaseCount('appointments', 0);
        });
    }
}
