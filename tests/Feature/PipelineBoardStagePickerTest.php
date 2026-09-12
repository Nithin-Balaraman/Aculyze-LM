<?php

namespace Tests\Feature;

use App\Filament\Pages\PipelineBoard;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\Proposal;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pipeline Board visual redesign, Section 6/9: with the per-stage boxes
 * gone, a same-lane drop onto the flattened lane carries no preset
 * destination any more — PipelineBoard::dropCandidateStages() computes the
 * record's currently-valid destinations (reusing isDropEligible()'s own
 * per-stage rule unchanged), and dropFormSchema() shows a picker only when
 * there is a genuine choice, skipping it for a foregone conclusion, and
 * showing nothing at all (the same "Not available" placeholder as before)
 * when there is none — Proposal above all, whose workflow this must never
 * bypass.
 */
class PipelineBoardStagePickerTest extends TestCase
{
    use RefreshDatabase;

    private function invokeDropCandidateStages(PipelineBoard $board, array $arguments, $record): array
    {
        $method = new \ReflectionMethod($board, 'dropCandidateStages');
        $method->setAccessible(true);

        return $method->invoke($board, $arguments, $record);
    }

    private function invokeDropFormSchema(PipelineBoard $board, array $arguments): array
    {
        $method = new \ReflectionMethod($board, 'dropFormSchema');
        $method->setAccessible(true);

        return $method->invoke($board, $arguments);
    }

    private function invokeIsAnyDropEligible(PipelineBoard $board, array $arguments): bool
    {
        $method = new \ReflectionMethod($board, 'isAnyDropEligible');
        $method->setAccessible(true);

        return $method->invoke($board, $arguments);
    }

    public function test_a_pending_follow_up_offers_both_its_real_next_statuses_as_a_picker(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $followUp = FollowUp::create([
                'prospect_id' => $prospect->id, 'user_id' => $user->id, 'follow_up_at' => now()->addDay(),
                'reason' => 'x', 'status' => 'pending',
            ]);

            $board = app(PipelineBoard::class);
            $arguments = ['resource' => 'follow_up', 'id' => $followUp->id];

            $candidates = $this->invokeDropCandidateStages($board, $arguments, $followUp);
            $this->assertSame(['completed', 'cancelled'], array_keys($candidates));

            $schema = $this->invokeDropFormSchema($board, $arguments);
            $this->assertInstanceOf(Select::class, $schema[0]);
            $this->assertTrue($this->invokeIsAnyDropEligible($board, $arguments));
        });
    }

    public function test_a_lead_offers_validated_and_lost_as_its_real_next_states(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $lead = Lead::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'stage' => 'requirement_collection', 'temperature' => 'warm',
            ]);

            $board = app(PipelineBoard::class);
            $candidates = $this->invokeDropCandidateStages($board, ['resource' => 'lead', 'id' => $lead->id], $lead);

            // Demo Scheduled/Done is never offered here — it's a real
            // LeadStage but isDropEligible() refuses it unconditionally
            // (superseded by the dedicated Demo lane/action).
            $this->assertSame(['validated', 'lost'], array_keys($candidates));
        });
    }

    /**
     * An already-Lost Lead has exactly one technically-valid "destination"
     * left ('lost' itself, re-confirming it — isDropEligible()'s own rule,
     * unchanged) — the redesign's own instruction says to skip the picker
     * for a foregone conclusion, so the dialog goes straight to the Lost
     * reason field via a hidden default instead of showing a one-option
     * Select.
     */
    public function test_an_already_lost_lead_skips_the_picker_since_lost_is_the_only_real_option(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $lead = Lead::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'stage' => 'validated', 'temperature' => 'hot', 'notes' => 'x',
            ]);
            $lead->markLost('Went with a competitor.');

            $board = app(PipelineBoard::class);
            $arguments = ['resource' => 'lead', 'id' => $lead->id];
            $candidates = $this->invokeDropCandidateStages($board, $arguments, $lead->fresh());
            $this->assertSame(['lost'], array_keys($candidates));

            $schema = $this->invokeDropFormSchema($board, $arguments);
            $this->assertInstanceOf(Hidden::class, $schema[0]);
            $this->assertSame('lost', $schema[0]->getDefaultState());
        });
    }

    /** No stage picker may ever bypass the Proposal workflow. */
    public function test_a_proposal_offers_no_same_lane_destinations_at_all(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $lead = Lead::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'stage' => 'validated', 'temperature' => 'hot', 'notes' => 'x',
            ]);
            $proposal = Proposal::create([
                'lead_id' => $lead->id, 'prospect_id' => $prospect->id,
                'assigned_to' => $user->id, 'created_by' => $user->id, 'stage' => 'being_prepared',
            ]);

            $board = app(PipelineBoard::class);
            $arguments = ['resource' => 'proposal', 'id' => $proposal->id];

            $this->assertSame([], $this->invokeDropCandidateStages($board, $arguments, $proposal));
            $this->assertFalse($this->invokeIsAnyDropEligible($board, $arguments));

            $schema = $this->invokeDropFormSchema($board, $arguments);
            $this->assertSame('unsupported', $schema[0]->getName());
        });
    }

    /**
     * End-to-end proof through the REAL mounted Filament Action (not just
     * the internal PHP methods) — confirms the picker's chosen value
     * actually reaches performDrop() unchanged, exactly as it always did
     * when a stage box supplied it directly.
     */
    public function test_picking_a_stage_in_the_live_modal_actually_performs_that_exact_transition(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $followUp = FollowUp::create([
                'prospect_id' => $prospect->id, 'user_id' => $user->id, 'follow_up_at' => now()->addDay(),
                'reason' => 'x', 'status' => 'pending',
            ]);

            Livewire::test(PipelineBoard::class)
                ->mountAction('drop', ['resource' => 'follow_up', 'id' => $followUp->id])
                ->setActionData([
                    'stage' => 'cancelled',
                    'notes' => 'No longer relevant.',
                ])
                ->callMountedAction();

            $this->assertSame('cancelled', $followUp->fresh()->status->value);
        });
    }
}
