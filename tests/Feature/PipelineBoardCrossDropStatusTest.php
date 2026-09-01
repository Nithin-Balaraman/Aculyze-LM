<?php

namespace Tests\Feature;

use App\Enums\AppointmentStage;
use App\Enums\AppointmentStatus;
use App\Enums\DemoMode;
use App\Enums\DemoStatus;
use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Filament\Pages\PipelineBoard;
use App\Models\Appointment;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Found while auditing every real Lead/Appointment creation path (Phase 2
 * verification round): PipelineBoard::createCrossDropDestination() — the
 * cross-lane drag-and-drop destination creator — created Appointments/
 * Leads without ever setting `status` explicitly, silently relying on the
 * NOT NULL column's DB default (scheduled/requirement_collection). That
 * default is WRONG whenever the destination stage isn't the default one —
 * e.g. cross-dropping straight onto Appointment's "Succeeded" box created
 * a row with stage=succeeded but status=scheduled, an inconsistent pair
 * the legacy-backfill mapping would never produce.
 *
 * Fixed by deriving `status` from the destination stage via
 * AppointmentStatus::fromLegacyStage()/LeadStatus::fromLegacyStage() — the
 * same single source of truth App\Console\Commands\
 * BackfillLeadAppointmentStatus uses, so the two can never diverge.
 */
class PipelineBoardCrossDropStatusTest extends TestCase
{
    use RefreshDatabase;

    private function invokeCreateCrossDropDestination(PipelineBoard $board, string $destResource, string $destStage, $source, array $data = []): void
    {
        $method = new \ReflectionMethod($board, 'createCrossDropDestination');
        $method->setAccessible(true);
        $method->invoke($board, $destResource, $destStage, $source, $data);
    }

    public function test_cross_drop_creating_an_appointment_at_a_terminal_stage_gets_the_matching_status_not_the_blind_default(): void
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
                'stage' => LeadStage::Validated,
                'status' => LeadStatus::RequirementConfirmed,
                'temperature' => 'hot',
                'notes' => 'Confirmed.',
            ]);

            $this->invokeCreateCrossDropDestination(
                app(PipelineBoard::class), 'appointment', AppointmentStage::Succeeded->value, $lead,
                ['destination_appointment_at' => now()->addDay(), 'destination_outcome_notes' => 'Went well.']
            );

            $appointment = Appointment::query()->where('prospect_id', $prospect->id)->firstOrFail();
            $this->assertSame(AppointmentStage::Succeeded, $appointment->stage);
            $this->assertSame(AppointmentStatus::Completed, $appointment->status, 'Succeeded must not be left at the blind Scheduled default.');
        });
    }

    public function test_cross_drop_creating_an_appointment_at_the_default_stage_still_gets_scheduled(): void
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
                'stage' => LeadStage::Validated,
                'status' => LeadStatus::RequirementConfirmed,
                'temperature' => 'hot',
                'notes' => 'Confirmed.',
            ]);

            $this->invokeCreateCrossDropDestination(
                app(PipelineBoard::class), 'appointment', AppointmentStage::AppointmentMade->value, $lead,
                ['destination_appointment_at' => now()->addDay()]
            );

            $appointment = Appointment::query()->where('prospect_id', $prospect->id)->firstOrFail();
            $this->assertSame(AppointmentStatus::Scheduled, $appointment->status);
        });
    }

    public function test_cross_drop_creating_a_lead_at_the_validated_stage_gets_requirement_confirmed_not_the_blind_default(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $followUp = \App\Models\FollowUp::create([
                'prospect_id' => $prospect->id,
                'user_id' => $user->id,
                'follow_up_at' => now()->addDay(),
                'reason' => 'Callback requested',
                'status' => 'pending',
            ]);

            $this->invokeCreateCrossDropDestination(
                app(PipelineBoard::class), 'lead', LeadStage::Validated->value, $followUp,
                ['destination_notes' => 'Confirmed on the call.']
            );

            $lead = Lead::query()->where('prospect_id', $prospect->id)->firstOrFail();
            $this->assertSame(LeadStage::Validated, $lead->stage);
            $this->assertSame(LeadStatus::RequirementConfirmed, $lead->status, 'Validated must not be left at the blind RequirementCollection default.');
        });
    }

    private function invokeResolveCrossDropSource(PipelineBoard $board, string $sourceResource, $source, array $data = []): void
    {
        $method = new \ReflectionMethod($board, 'resolveCrossDropSource');
        $method->setAccessible(true);
        $method->invoke($board, $sourceResource, $source, $data);
    }

    /**
     * Phase 3 fix: resolveCrossDropSource() resolves the DRAGGED SOURCE
     * card forward (e.g. an Appointment source resolving to Succeeded when
     * its Lead reaches Proposal) — before this fix it wrote only legacy
     * `stage`, leaving `status` silently stale. This is the one confirmed
     * stage/status divergence gap identified during the Phase 3 audit.
     */
    public function test_resolving_an_appointment_cross_drop_source_sets_status_alongside_stage(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $appointment = Appointment::create([
                'prospect_id' => $prospect->id,
                'assigned_to' => $user->id,
                'created_by' => $user->id,
                'appointment_at' => now()->addDay(),
                'stage' => AppointmentStage::AppointmentMade,
                'status' => AppointmentStatus::Scheduled,
            ]);

            $this->invokeResolveCrossDropSource(
                app(PipelineBoard::class), 'appointment', $appointment, ['source_outcome_notes' => 'Led to a real requirement.']
            );

            $appointment->refresh();
            $this->assertSame(AppointmentStage::Succeeded, $appointment->stage);
            $this->assertSame(AppointmentStatus::Completed, $appointment->status, 'Resolving the source forward must not leave status stale.');
        });
    }

    public function test_resolving_a_lead_cross_drop_source_sets_status_alongside_stage(): void
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
                'stage' => LeadStage::RequirementCollection,
                'status' => LeadStatus::RequirementCollection,
                'temperature' => 'warm',
            ]);

            $this->invokeResolveCrossDropSource(
                app(PipelineBoard::class), 'lead', $lead, ['source_notes' => 'Confirmed while creating the Proposal.']
            );

            $lead->refresh();
            $this->assertSame(LeadStage::Validated, $lead->stage);
            $this->assertSame(LeadStatus::RequirementConfirmed, $lead->status, 'Resolving the source forward must not leave status stale.');
        });
    }

    private function invokeCrossDropSupported(PipelineBoard $board, ?string $sourceResource, ?string $destResource, $source, string $destStage = ''): bool
    {
        $method = new \ReflectionMethod($board, 'crossDropSupported');
        $method->setAccessible(true);

        return $method->invoke($board, $sourceResource, $destResource, $source, $destStage);
    }

    private function invokePerformCrossDrop(PipelineBoard $board, array $arguments, array $data = []): void
    {
        $method = new \ReflectionMethod($board, 'performCrossDrop');
        $method->setAccessible(true);
        $method->invoke($board, $arguments, $data);
    }

    /**
     * Phase 3: Demo has no legacy stage, so it is a valid cross-drop
     * DESTINATION only from an existing Lead with no Scheduled Demo already
     * open — never a drag source itself (Demo -> Proposal/Follow-Up/Lead
     * only ever happens through DemoResource's own Record Outcome action).
     */
    public function test_lead_to_demo_cross_drop_is_supported_until_a_scheduled_demo_already_exists(): void
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
                'stage' => LeadStage::RequirementCollection,
                'status' => LeadStatus::RequirementCollection,
                'temperature' => 'warm',
            ]);

            $board = app(PipelineBoard::class);

            $this->assertTrue($this->invokeCrossDropSupported($board, 'lead', 'demo', $lead));

            Demo::create([
                'prospect_id' => $lead->prospect_id,
                'lead_id' => $lead->id,
                'assigned_to' => $user->id,
                'created_by' => $user->id,
                'demo_at' => now()->addDays(2),
                'mode' => DemoMode::Online,
                'meeting_link' => 'https://meet.example.com/a',
                'status' => DemoStatus::Scheduled,
            ]);

            $this->assertFalse($this->invokeCrossDropSupported($board, 'lead', 'demo', $lead->fresh()));
        });
    }

    /**
     * The cross-drop dialog for Demo is routed through the exact same
     * WorkflowTransitionService::transitionToDemo() every other
     * Demo-creating path (LeadResource's own "Schedule Demo" row action
     * included) uses — see createCrossDropDestination()'s 'demo' case —
     * rather than a raw Demo::create() the way the other three destination
     * types still use.
     */
    public function test_cross_dropping_a_lead_onto_the_demo_lane_creates_a_scheduled_demo_via_the_centralized_transition_service(): void
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
                'stage' => LeadStage::RequirementCollection,
                'status' => LeadStatus::RequirementCollection,
                'temperature' => 'warm',
            ]);

            $this->invokePerformCrossDrop(
                app(PipelineBoard::class),
                ['sourceResource' => 'lead', 'sourceId' => $lead->id, 'destResource' => 'demo', 'destStage' => DemoStatus::Scheduled->value],
                [
                    'destination_demo_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
                    'destination_mode' => DemoMode::Online->value,
                    'destination_meeting_link' => 'https://meet.example.com/demo',
                ]
            );

            $demo = Demo::sole();
            $this->assertSame($lead->id, $demo->lead_id);
            $this->assertSame('lead', $demo->origin_type);
            $this->assertSame($lead->id, $demo->origin_id);
            $this->assertSame(DemoStatus::Scheduled, $demo->status);

            // Dragging the Lead into Demo does NOT itself resolve the Lead
            // forward the way dragging it into Proposal does — Demo is
            // optional and Lead -> Proposal remains valid directly, so the
            // Lead's own stage/status are untouched by this transition.
            $this->assertSame(LeadStage::RequirementCollection, $lead->fresh()->stage);
        });
    }
}
