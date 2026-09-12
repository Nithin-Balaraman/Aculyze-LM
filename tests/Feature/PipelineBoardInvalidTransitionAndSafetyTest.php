<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Filament\Pages\PipelineBoard;
use App\Models\Appointment;
use App\Models\CallRecord;
use App\Models\Demo;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Proposal;
use App\Models\Prospect;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pipeline Board redesign, Section 17 items 10-14: every blocked cross-drop
 * must leave the dragged source completely unchanged and create no
 * downstream record — never a raw exception, never a silent partial write.
 * Section 8 also documents specific rules this file locks down: Calls can
 * never reach Demo or Proposal directly (both need an existing, Validated
 * Lead behind them — see crossDropSupported()'s own docblock), and an
 * Appointment/Follow-up card can never cross-drop straight into Demo or
 * Proposal either — both destinations require an EXISTING Lead reference
 * (Demo needs `lead_id`, Proposal needs `lead_id` too), which an Appointment
 * or Follow-up card does not itself carry. That path already exists — it's
 * AppointmentOutcome::DemoRequired/ProposalRequired via WorkflowTransition
 * Service::transitionAppointmentOutcome(), reached through AppointmentResource's
 * own "Record Outcome" action, exactly mirroring why Demo itself is never a
 * cross-drop SOURCE (see PipelineBoard's own crossDropSupported() docblock).
 * This is a deliberate, current, hardened rule — not a gap — documented and
 * tested here per this phase's own instruction to "document and test the
 * current rule" wherever a listed pair is no longer authoritative.
 */
class PipelineBoardInvalidTransitionAndSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function invokePerformCrossDrop(PipelineBoard $board, array $arguments, array $data = []): void
    {
        $method = new \ReflectionMethod($board, 'performCrossDrop');
        $method->setAccessible(true);
        $method->invoke($board, $arguments, $data);
    }

    private function invokeIsCrossDropEligible(PipelineBoard $board, array $arguments): bool
    {
        $method = new \ReflectionMethod($board, 'isCrossDropEligible');
        $method->setAccessible(true);

        return $method->invoke($board, $arguments);
    }

    public function test_call_to_demo_cross_drop_is_blocked(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $call = CallRecord::create([
                'prospect_id' => $prospect->id, 'user_id' => $user->id,
                'called_at' => now()->subDay(), 'outcome' => CallOutcome::NoAnswer,
            ]);

            $board = app(PipelineBoard::class);
            $this->assertFalse($this->invokeIsCrossDropEligible($board, [
                'sourceResource' => 'call', 'sourceId' => $call->id, 'destResource' => 'demo', 'destStage' => 'scheduled',
            ]));

            $this->invokePerformCrossDrop($board, [
                'sourceResource' => 'call', 'sourceId' => $call->id, 'destResource' => 'demo', 'destStage' => 'scheduled',
            ], ['destination_demo_at' => now()->addDay(), 'destination_mode' => 'online', 'destination_meeting_link' => 'https://example.com']);

            $this->assertSame(0, Demo::query()->count());
            $this->assertSame(1, CallRecord::query()->count(), 'A blocked cross-drop must never log a new Call either.');
        });
    }

    public function test_call_to_proposal_cross_drop_is_blocked_with_no_valid_lead_path(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $call = CallRecord::create([
                'prospect_id' => $prospect->id, 'user_id' => $user->id,
                'called_at' => now()->subDay(), 'outcome' => CallOutcome::NoAnswer,
            ]);

            $board = app(PipelineBoard::class);
            $this->assertFalse($this->invokeIsCrossDropEligible($board, [
                'sourceResource' => 'call', 'sourceId' => $call->id, 'destResource' => 'proposal', 'destStage' => 'being_prepared',
            ]));

            $this->invokePerformCrossDrop($board, [
                'sourceResource' => 'call', 'sourceId' => $call->id, 'destResource' => 'proposal', 'destStage' => 'being_prepared',
            ], []);

            $this->assertSame(0, Proposal::query()->count());
        });
    }

    /**
     * Deliberate, current, hardened rule (see class docblock): an
     * Appointment card cannot cross-drop directly into Proposal — Proposal
     * always needs a real, EXISTING Lead behind it, which an Appointment
     * doesn't itself carry. The approved path is AppointmentOutcome::
     * ProposalRequired via AppointmentResource's own "Record Outcome"
     * action (WorkflowTransitionService::transitionAppointmentOutcome()),
     * which asks for the specific Lead to attach to.
     */
    public function test_appointment_to_proposal_direct_cross_drop_is_blocked(): void
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

            $board = app(PipelineBoard::class);
            $this->assertFalse($this->invokeIsCrossDropEligible($board, [
                'sourceResource' => 'appointment', 'sourceId' => $appointment->id, 'destResource' => 'proposal', 'destStage' => 'being_prepared',
            ]));

            $this->invokePerformCrossDrop($board, [
                'sourceResource' => 'appointment', 'sourceId' => $appointment->id, 'destResource' => 'proposal', 'destStage' => 'being_prepared',
            ], []);

            $this->assertSame(0, Proposal::query()->count());
            $appointment->refresh();
            $this->assertSame('appointment_made', $appointment->stage->value, 'The blocked drag must leave the Appointment card completely unchanged.');
        });
    }

    /** Same reasoning as the Appointment case above — Demo also always needs an existing Lead. */
    public function test_appointment_to_demo_direct_cross_drop_is_blocked(): void
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

            $board = app(PipelineBoard::class);
            $this->assertFalse($this->invokeIsCrossDropEligible($board, [
                'sourceResource' => 'appointment', 'sourceId' => $appointment->id, 'destResource' => 'demo', 'destStage' => 'scheduled',
            ]));

            $this->assertSame(0, Demo::query()->count());
        });
    }

    /**
     * Phase 4A-3.5 cutover regression guard: a Proposal card can never be
     * dragged as a cross-drop source into ANY lane — see
     * ProposalOutcomeCutoverGateTest.php for the exhaustive same-lane
     * coverage; this is the cross-lane-source counterpart.
     */
    public function test_proposal_source_cross_drop_into_follow_up_is_blocked_and_leaves_the_proposal_untouched(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $lead = Lead::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'stage' => LeadStage::Validated, 'status' => LeadStatus::ProposalRequired, 'temperature' => 'hot', 'notes' => 'x',
            ]);
            $proposal = Proposal::create([
                'lead_id' => $lead->id, 'prospect_id' => $prospect->id,
                'assigned_to' => $user->id, 'created_by' => $user->id, 'stage' => 'being_prepared',
            ]);

            $board = app(PipelineBoard::class);
            $this->assertFalse($this->invokeIsCrossDropEligible($board, [
                'sourceResource' => 'proposal', 'sourceId' => $proposal->id, 'destResource' => 'follow_up', 'destStage' => 'pending',
            ]));

            $this->invokePerformCrossDrop($board, [
                'sourceResource' => 'proposal', 'sourceId' => $proposal->id, 'destResource' => 'follow_up', 'destStage' => 'pending',
            ], ['destination_reason' => 'x', 'destination_follow_up_at' => now()->addDay()]);

            $this->assertSame(0, FollowUp::query()->count());
            $proposal->refresh();
            $this->assertSame('being_prepared', $proposal->stage->value);
            $this->assertNull($proposal->outcome);
        });
    }

    /**
     * A dragged source is finalized (resolved forward) AT MOST once — see
     * WorkflowTransitionService::finalizeCrossDroppedAppointment()'s own
     * "already resolved" guard and isAlreadyResolved()'s check in
     * performCrossDrop(). Re-dragging an already-Succeeded Appointment card
     * to create ANOTHER Follow-Up is legitimate (the same "generate another
     * downstream artifact from a closed activity" shape as "Schedule
     * Another Demo" — createCrossDropDestination() has no reason to refuse
     * it), but the source's OWN finalization must never be attempted twice:
     * the second cross-drop must neither throw nor silently re-run
     * finalizeCrossDroppedAppointment() a second time.
     */
    public function test_finalizing_an_already_resolved_source_is_skipped_on_a_repeated_cross_drop_without_error(): void
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

            $board = app(PipelineBoard::class);
            $arguments = ['sourceResource' => 'appointment', 'sourceId' => $appointment->id, 'destResource' => 'follow_up', 'destStage' => 'pending'];
            $data = [
                'destination_reason' => 'Needs a follow-up.',
                'destination_follow_up_at' => now()->addDay(),
                'source_outcome_notes' => 'Went well overall.',
            ];

            $this->invokePerformCrossDrop($board, $arguments, $data);
            $appointment->refresh();
            $this->assertSame('succeeded', $appointment->stage->value);
            $firstResolvedAt = $appointment->stage_changed_at;

            // Re-dragging the now-Succeeded card creates a second, genuinely
            // separate Follow-Up — that part is legitimate — but must not
            // throw ("already resolved") or re-touch the Appointment's own
            // already-final stage/timestamp a second time.
            $this->invokePerformCrossDrop($board, $arguments, $data);

            $this->assertSame(2, FollowUp::query()->count());
            $appointment->refresh();
            $this->assertSame('succeeded', $appointment->stage->value);
            $this->assertTrue($firstResolvedAt->equalTo($appointment->stage_changed_at), 'A second cross-drop must never re-finalize an already-resolved source.');
        });
    }
}
