<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Enums\FollowUpStatus;
use App\Filament\Pages\PipelineBoard;
use App\Models\Appointment;
use App\Models\CallRecord;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pipeline Board redesign, Section 17 items 15-17: Call is the one
 * cross-drop SOURCE that never creates the destination type directly (see
 * PipelineBoard::logNewCall()/performCrossDrop()'s own docblocks) — dragging
 * a Call card logs a brand-new Call Record for the same Prospect through the
 * exact same CallRecordObserver -> CallRoutingService path a fresh "Log a
 * Call" goes through, and the outcome the rep picks decides what (if
 * anything) is created downstream. CallRoutingTest.php already covers
 * CallRoutingService itself exhaustively; this file is the missing coverage
 * for the board's OWN cross-drop entry point into that same path — before
 * this file, no test exercised `sourceResource === 'call'` through
 * performCrossDrop() at all.
 */
class PipelineBoardCallCrossDropTest extends TestCase
{
    use RefreshDatabase;

    private function invokePerformCrossDrop(PipelineBoard $board, array $arguments, array $data = []): void
    {
        $method = new \ReflectionMethod($board, 'performCrossDrop');
        $method->setAccessible(true);
        $method->invoke($board, $arguments, $data);
    }

    public function test_call_to_follow_up_cross_drop_logs_a_new_call_and_routes_to_a_follow_up(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $call = CallRecord::create([
                'prospect_id' => $prospect->id,
                'user_id' => $user->id,
                'called_at' => now()->subDay(),
                'outcome' => CallOutcome::NoAnswer,
            ]);

            $this->invokePerformCrossDrop(
                app(PipelineBoard::class),
                ['sourceResource' => 'call', 'sourceId' => $call->id, 'destResource' => 'follow_up', 'destStage' => 'pending'],
                [
                    'outcome' => CallOutcome::CallbackRequested->value,
                    'notes' => 'Asked to call back next week.',
                    'follow_up_at' => now()->addDays(3),
                    'called_at' => now(),
                ],
            );

            // The dragged Call Record itself is never touched — a brand-new
            // one was logged instead.
            $this->assertSame(2, CallRecord::query()->count());
            $newCall = CallRecord::query()->where('id', '!=', $call->id)->sole();
            $this->assertSame(CallOutcome::CallbackRequested, $newCall->outcome);

            $followUp = FollowUp::query()->where('prospect_id', $prospect->id)->sole();
            $this->assertSame(FollowUpStatus::Pending, $followUp->status);
        });
    }

    public function test_call_to_appointment_cross_drop_logs_a_new_call_and_routes_to_an_appointment(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $call = CallRecord::create([
                'prospect_id' => $prospect->id,
                'user_id' => $user->id,
                'called_at' => now()->subDay(),
                'outcome' => CallOutcome::NoAnswer,
            ]);

            $this->invokePerformCrossDrop(
                app(PipelineBoard::class),
                ['sourceResource' => 'call', 'sourceId' => $call->id, 'destResource' => 'appointment', 'destStage' => 'appointment_made'],
                [
                    'outcome' => CallOutcome::AppointmentSet->value,
                    'notes' => 'Agreed to a site visit.',
                    'appointment_at' => now()->addDays(3),
                    'called_at' => now(),
                ],
            );

            $this->assertSame(2, CallRecord::query()->count());
            $appointment = Appointment::query()->where('prospect_id', $prospect->id)->sole();
            $this->assertNotNull($appointment);
        });
    }

    public function test_call_to_lead_cross_drop_logs_a_new_call_and_routes_to_a_lead(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $call = CallRecord::create([
                'prospect_id' => $prospect->id,
                'user_id' => $user->id,
                'called_at' => now()->subDay(),
                'outcome' => CallOutcome::NoAnswer,
            ]);

            $this->invokePerformCrossDrop(
                app(PipelineBoard::class),
                ['sourceResource' => 'call', 'sourceId' => $call->id, 'destResource' => 'lead', 'destStage' => 'requirement_collection'],
                [
                    'outcome' => CallOutcome::RequirementIdentified->value,
                    'notes' => 'Interested in a full rollout.',
                    'called_at' => now(),
                ],
            );

            $this->assertSame(2, CallRecord::query()->count());
            $lead = Lead::query()->where('prospect_id', $prospect->id)->sole();
            $this->assertNotNull($lead);
        });
    }

    /**
     * The dragged Call card is never "resolved" or mutated — logging
     * another real call for the same prospect is always legitimate, so
     * there's no "already linked" restriction (see crossDropSupported()'s
     * own docblock for the Call case).
     */
    public function test_the_dragged_call_record_itself_is_never_mutated_by_a_cross_drop(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $call = CallRecord::create([
                'prospect_id' => $prospect->id,
                'user_id' => $user->id,
                'called_at' => now()->subDay(),
                'outcome' => CallOutcome::NoAnswer,
            ]);

            $originalOutcome = $call->outcome->value;
            $originalNotes = $call->notes;

            $this->invokePerformCrossDrop(
                app(PipelineBoard::class),
                ['sourceResource' => 'call', 'sourceId' => $call->id, 'destResource' => 'lead', 'destStage' => 'requirement_collection'],
                ['outcome' => CallOutcome::RequirementIdentified->value, 'notes' => 'Interested.', 'called_at' => now()],
            );

            $call->refresh();
            $this->assertSame($originalOutcome, $call->outcome->value);
            $this->assertSame($originalNotes, $call->notes);
        });
    }
}
