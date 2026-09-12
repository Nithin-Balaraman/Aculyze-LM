<?php

namespace Tests\Feature;

use App\Enums\AppointmentOutcome;
use App\Enums\AppointmentStage;
use App\Enums\AppointmentStatus;
use App\Enums\DemoNextAction;
use App\Enums\DemoOutcome;
use App\Enums\DemoStatus;
use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\ProposalStage;
use App\Enums\ProposalVersionLifecycle;
use App\Enums\UserRole;
use App\Filament\Pages\PipelineBoard;
use App\Filament\Resources\ProposalResource\Pages\CreateProposal;
use App\Models\Appointment;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\Prospect;
use App\Models\User;
use App\Services\WorkflowTransitionService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Phase 4A-2.4: proves each of the four runtime Proposal-creation paths
 * (Appointment outcome, Demo outcome, PipelineBoard cross-drop, the direct
 * Filament Create page) now goes through the centralized
 * ProposalCreationService and always ends with a real V1 Draft +
 * current_version_id, while preserving each path's own pre-existing parent
 * Proposal semantics exactly. ProposalCreationServiceTest covers the
 * service's own contract in isolation.
 */
class ProposalCreationRuntimePathsTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // D. APPOINTMENT PATH
    // -----------------------------------------------------------------

    public function test_appointment_to_proposal_path_creates_proposal_with_v1_and_current_version_id(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $lead = Lead::create([
                'prospect_id' => $prospect->id,
                'assigned_to' => $user->id,
                'created_by' => $user->id,
                'stage' => LeadStage::Validated,
                'status' => LeadStatus::ProposalRequired,
                'temperature' => LeadTemperature::Hot,
                'notes' => 'Confirmed requirement.',
            ]);
            $appointment = Appointment::create([
                'prospect_id' => $prospect->id,
                'assigned_to' => $user->id,
                'created_by' => $user->id,
                'appointment_at' => now()->addDays(2),
                'stage' => AppointmentStage::AppointmentMade,
                'status' => AppointmentStatus::Scheduled,
            ]);

            app(WorkflowTransitionService::class)->transitionAppointmentOutcome(
                $appointment,
                AppointmentOutcome::ProposalRequired,
                ['lead_id' => $lead->id, 'outcome_notes' => 'Ready for a Proposal.']
            );

            $proposal = Proposal::where('lead_id', $lead->id)->firstOrFail();
            $this->assertNotNull($proposal->current_version_id);

            $version = ProposalVersion::where('proposal_id', $proposal->id)->firstOrFail();
            $this->assertSame($version->id, $proposal->current_version_id);
            $this->assertSame(ProposalVersionLifecycle::Draft, $version->lifecycle_status);

            // Existing parent stage/assigned_to semantics preserved exactly.
            $this->assertSame(ProposalStage::BeingPrepared, $proposal->stage);
            $this->assertSame($lead->assigned_to, $proposal->assigned_to);
            $this->assertSame($lead->created_by, $proposal->created_by);

            // No regression to the Appointment's own outcome behavior.
            $this->assertSame(AppointmentOutcome::ProposalRequired, $appointment->fresh()->outcome);
            $this->assertSame(AppointmentStatus::Completed, $appointment->fresh()->status);
        });
    }

    // -----------------------------------------------------------------
    // E. DEMO PATH
    // -----------------------------------------------------------------

    public function test_demo_to_proposal_path_creates_proposal_with_v1_and_current_version_id(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Employee]);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $lead = Lead::create([
                'prospect_id' => $prospect->id,
                'assigned_to' => $user->id,
                'created_by' => $user->id,
                'stage' => LeadStage::RequirementCollection,
                'status' => LeadStatus::RequirementCollection,
                'temperature' => LeadTemperature::Warm,
            ]);
            $demo = Demo::create([
                'prospect_id' => $prospect->id,
                'lead_id' => $lead->id,
                'assigned_to' => $user->id,
                'created_by' => $user->id,
                'mode' => 'online',
                'demo_at' => now()->addDays(1),
                'meeting_link' => 'https://example.test/demo',
                'status' => 'scheduled',
            ]);

            app(WorkflowTransitionService::class)->transitionDemoOutcome($demo, DemoOutcome::ProposalRequired, []);

            $proposal = Proposal::where('lead_id', $lead->id)->firstOrFail();
            $this->assertNotNull($proposal->current_version_id);

            $version = ProposalVersion::where('proposal_id', $proposal->id)->firstOrFail();
            $this->assertSame($version->id, $proposal->current_version_id);
            $this->assertSame(ProposalVersionLifecycle::Draft, $version->lifecycle_status);

            $this->assertSame($lead->assigned_to, $proposal->assigned_to);
            $this->assertSame($lead->created_by, $proposal->created_by);

            // No regression to the Demo's own outcome behavior.
            $this->assertSame(DemoOutcome::ProposalRequired, $demo->fresh()->outcome);
            $this->assertSame(DemoNextAction::StartProposal, $demo->fresh()->next_action);
            $this->assertSame(DemoStatus::Completed, $demo->fresh()->status);
        });
    }

    // -----------------------------------------------------------------
    // F. PIPELINE BOARD PATH
    // -----------------------------------------------------------------

    public function test_pipeline_board_lead_cross_drop_to_proposal_creates_v1_and_current_version_id(): void
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
                'status' => LeadStatus::ProposalRequired,
                'temperature' => LeadTemperature::Hot,
                'notes' => 'Confirmed requirement and budget.',
            ]);

            $board = app(PipelineBoard::class);

            $this->invokePerformCrossDrop(
                $board,
                ['sourceResource' => 'lead', 'sourceId' => $lead->id, 'destResource' => 'proposal', 'destStage' => 'being_prepared'],
                [],
            );

            $proposal = Proposal::where('lead_id', $lead->id)->firstOrFail();
            $this->assertSame(1, Proposal::where('lead_id', $lead->id)->count());
            $this->assertNotNull($proposal->current_version_id);

            $version = ProposalVersion::where('proposal_id', $proposal->id)->firstOrFail();
            $this->assertSame($version->id, $proposal->current_version_id);
            $this->assertSame(ProposalVersionLifecycle::Draft, $version->lifecycle_status);

            // Existing destination-stage/assignment semantics preserved:
            // "being_prepared" carries no automatic outcome, and the
            // assignment falls back to the Prospect's own assigned_to.
            $this->assertNull($proposal->outcome);
            $this->assertSame($prospect->assigned_to, $proposal->assigned_to);

            // The dragged Lead source itself is finalized, same as before.
            $this->assertSame(LeadStatus::ProposalRequired, $lead->fresh()->status);
        });
    }

    /**
     * Phase 4A-3.5 cutover (Decision 17): a Lead cross-dropped straight onto
     * a terminal Proposal-lane column used to fabricate an already-Won
     * Proposal with no ProposalVersion workflow, no Client Response, and no
     * winning Version at all — silently violating "Accepted is the only v1
     * route to Won" and (post-cutover) the DB's own Won -> winning_version_id
     * CHECK. crossDropSupported() now refuses this combination outright, so
     * the cross-drop is a genuine no-op: nothing is created.
     */
    public function test_pipeline_board_proposal_cross_drop_with_won_destination_stage_is_refused(): void
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
                'status' => LeadStatus::ProposalRequired,
                'temperature' => LeadTemperature::Hot,
                'notes' => 'Confirmed requirement and budget.',
            ]);

            $board = app(PipelineBoard::class);

            $this->invokePerformCrossDrop(
                $board,
                ['sourceResource' => 'lead', 'sourceId' => $lead->id, 'destResource' => 'proposal', 'destStage' => 'customer_accepted'],
                ['destination_notes' => 'Signed on the spot.'],
            );

            $this->assertSame(0, Proposal::where('lead_id', $lead->id)->count());
        });
    }

    private function invokePerformCrossDrop(PipelineBoard $board, array $arguments, array $data = []): void
    {
        $method = new ReflectionMethod($board, 'performCrossDrop');
        $method->setAccessible(true);
        $method->invoke($board, $arguments, $data);
    }

    // -----------------------------------------------------------------
    // G. DIRECT CREATE PAGE / DOMAIN PATH
    // -----------------------------------------------------------------

    public function test_direct_create_page_creates_proposal_with_v1(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Manager]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $lead = Lead::create([
                'prospect_id' => $prospect->id,
                'assigned_to' => $user->id,
                'created_by' => $user->id,
                'stage' => LeadStage::Validated,
                'status' => LeadStatus::ProposalRequired,
                'temperature' => LeadTemperature::Hot,
                'notes' => 'Confirmed requirement and budget.',
            ]);

            Livewire::test(CreateProposal::class)
                ->fillForm([
                    'lead_id' => $lead->id,
                    'assigned_to' => $user->id,
                    'stage' => 'being_prepared',
                ])
                ->call('create')
                ->assertHasNoFormErrors();

            $proposal = Proposal::where('lead_id', $lead->id)->firstOrFail();
            $this->assertNotNull($proposal->current_version_id);

            $version = ProposalVersion::where('proposal_id', $proposal->id)->firstOrFail();
            $this->assertSame($version->id, $proposal->current_version_id);

            // Existing form-supplied legitimate parent fields preserved.
            $this->assertSame($user->id, $proposal->assigned_to);
            $this->assertSame($user->id, $proposal->created_by);
        });
    }

    public function test_no_direct_raw_proposal_create_remains_in_the_create_page_runtime_path(): void
    {
        $contents = file_get_contents(app_path('Filament/Resources/ProposalResource/Pages/CreateProposal.php'));

        $this->assertStringNotContainsString('Proposal::create(', $contents);
        $this->assertStringContainsString('ProposalCreationService', $contents);
    }
}
