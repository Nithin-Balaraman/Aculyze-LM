<?php

namespace Tests\Feature;

use App\Enums\AppointmentStage;
use App\Enums\AppointmentStatus;
use App\Enums\CallOutcome;
use App\Enums\DemoMode;
use App\Enums\DemoStatus;
use App\Enums\FollowUpStatus;
use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Filament\Pages\PipelineBoard;
use App\Models\Appointment;
use App\Models\Demo;
use App\Models\FollowUp;
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
     *
     * Correction round: this is classification D (an explicit,
     * administrative, audited correction), not classification A — calling
     * WorkflowTransitionService here would create a SECOND downstream
     * Follow-Up/Lead on top of the one createCrossDropDestination() already
     * created for the same cross-drop. It must still be audited like any
     * other administrative correction (see CallRoutingService::
     * correctOutcome()'s own audit requirement) — this proves that too.
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
            $this->assertDatabaseHas('audit_events', [
                'entity_type' => 'Appointment',
                'entity_id' => $appointment->id,
                'action' => 'appointment_resolved_via_cross_drop',
            ]);
        });
    }

    /**
     * Correction round: this previously used
     * LeadStatus::fromLegacyStage(Validated) = RequirementConfirmed — the
     * conservative mapping meant for historical backfill, not for a Lead
     * that is, right now, actually getting a real Proposal out of this
     * exact cross-drop. ProposalRequired is the correct, meaningful status
     * here — the same one LeadResource's "Create Proposal" eligibility and
     * Lead's own Notes guard already treat as equivalent to stage=Validated.
     */
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
            $this->assertSame(LeadStatus::ProposalRequired, $lead->status, 'A Lead resolved forward into a real Proposal must reflect that in its normalized status, not the conservative historical-backfill mapping.');
            $this->assertDatabaseHas('audit_events', [
                'entity_type' => 'Lead',
                'entity_id' => $lead->id,
                'action' => 'lead_resolved_via_cross_drop',
            ]);
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

    /**
     * Phase 3 correction round 2, end-to-end proof (via the real mounted
     * cross-drop entry point, not a direct call into
     * resolveCrossDropSource()): a single cross-drop creates exactly one
     * destination AND finalizes the source exactly once. The two
     * responsibilities createCrossDropDestination() and
     * WorkflowTransitionService::finalizeCrossDroppedAppointment() now
     * split between them can never run more than once for the same
     * cross-drop, and never leave a duplicate downstream record behind.
     */
    public function test_a_single_appointment_to_follow_up_cross_drop_creates_exactly_one_follow_up_and_finalizes_the_source_exactly_once(): void
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

            $this->invokePerformCrossDrop(
                app(PipelineBoard::class),
                ['sourceResource' => 'appointment', 'sourceId' => $appointment->id, 'destResource' => 'follow_up', 'destStage' => 'pending'],
                [
                    'destination_reason' => 'Needs internal approval first.',
                    'destination_follow_up_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
                    'source_outcome_notes' => 'Went well, needs a follow-up.',
                ]
            );

            $this->assertSame(1, \App\Models\FollowUp::where('prospect_id', $prospect->id)->count(), 'Exactly one destination Follow-Up, never a duplicate.');

            $appointment->refresh();
            $this->assertSame(AppointmentStage::Succeeded, $appointment->stage);
            $this->assertSame(AppointmentStatus::Completed, $appointment->status);

            $this->assertSame(1, \App\Models\AuditEvent::query()
                ->where('entity_type', 'Appointment')
                ->where('entity_id', $appointment->id)
                ->where('action', 'appointment_resolved_via_cross_drop')
                ->count(), 'Source finalization must occur exactly once per cross-drop.');
        });
    }

    private function makeFollowUp(User $user, Prospect $prospect): FollowUp
    {
        return FollowUp::create([
            'prospect_id' => $prospect->id,
            'user_id' => $user->id,
            'follow_up_at' => now()->addDay(),
            'reason' => 'Test follow-up',
            'status' => FollowUpStatus::Pending,
        ]);
    }

    /**
     * Regression test for a genuine duplicate-Appointment bug: dragging a
     * Follow-Up into the Appointment lane created TWO Appointments when the
     * "Also resolving: Follow-up" dialog's Call Outcome was set to
     * Appointment Set — one from createCrossDropDestination() (the actual
     * drag destination) and a SECOND, independent one from
     * CallRoutingService::createAppointment(), triggered by the CallRecord
     * completeWithCall() logs to close out the Follow-Up.
     *
     * The happy path: the rep picks any Call Outcome that does NOT also
     * route to an Appointment (Appointment Set is no longer even offered —
     * see the options-filtering test below) to close out the Follow-Up
     * call log, while the actual new Appointment is the one
     * createCrossDropDestination() creates for the drag itself.
     */
    public function test_follow_up_to_appointment_cross_drop_creates_exactly_one_appointment(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $followUp = $this->makeFollowUp($user, $prospect);

            $this->invokePerformCrossDrop(
                app(PipelineBoard::class),
                ['sourceResource' => 'follow_up', 'sourceId' => $followUp->id, 'destResource' => 'appointment', 'destStage' => AppointmentStage::AppointmentMade->value],
                [
                    'destination_appointment_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
                    // Does not route anywhere on its own — proves the ONE
                    // Appointment that does exist came only from
                    // createCrossDropDestination().
                    'source_outcome' => CallOutcome::NoAnswer->value,
                ]
            );

            $this->assertSame(1, Appointment::where('prospect_id', $prospect->id)->count(), 'Exactly one Appointment, never a duplicate.');

            $followUp->refresh();
            $this->assertSame(FollowUpStatus::Completed, $followUp->status);
        });
    }

    /**
     * Symmetric case: the same duplicate risk exists for Follow-Up -> Lead
     * when the resolving Call Outcome is Requirement Identified (the one
     * other outcome that independently routes to a Lead via
     * CallRoutingService).
     */
    public function test_follow_up_to_lead_cross_drop_creates_exactly_one_lead(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $followUp = $this->makeFollowUp($user, $prospect);

            $this->invokePerformCrossDrop(
                app(PipelineBoard::class),
                ['sourceResource' => 'follow_up', 'sourceId' => $followUp->id, 'destResource' => 'lead', 'destStage' => LeadStage::RequirementCollection->value],
                [
                    'destination_temperature' => 'warm',
                    'source_outcome' => CallOutcome::NoAnswer->value,
                ]
            );

            $this->assertSame(1, Lead::where('prospect_id', $prospect->id)->count(), 'Exactly one Lead, never a duplicate.');

            $followUp->refresh();
            $this->assertSame(FollowUpStatus::Completed, $followUp->status);
        });
    }

    /**
     * Defense-in-depth: even a forged/direct request that bypasses the
     * options-filtering above and submits the duplicate-causing outcome
     * anyway must be rejected atomically — the destination Appointment
     * createCrossDropDestination() already created inside the same
     * transaction must roll back too, never left dangling while the
     * Follow-Up resolution silently fails.
     */
    public function test_the_disallowed_outcome_combination_is_rejected_atomically_not_left_partially_applied(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $followUp = $this->makeFollowUp($user, $prospect);

            try {
                $this->invokePerformCrossDrop(
                    app(PipelineBoard::class),
                    ['sourceResource' => 'follow_up', 'sourceId' => $followUp->id, 'destResource' => 'appointment', 'destStage' => AppointmentStage::AppointmentMade->value],
                    [
                        'destination_appointment_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
                        'source_outcome' => CallOutcome::AppointmentSet->value,
                        'source_appointment_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
                        'source_notes' => 'Agreed to a site visit.',
                    ]
                );
                $this->fail('Expected the disallowed outcome combination to be rejected.');
            } catch (\Filament\Support\Exceptions\Halt) {
                // expected
            }

            $this->assertSame(0, Appointment::where('prospect_id', $prospect->id)->count(), 'The whole cross-drop must roll back, not leave the destination half-created.');

            $followUp->refresh();
            $this->assertSame(FollowUpStatus::Pending, $followUp->status, 'A rejected cross-drop must leave the Follow-Up untouched.');
        });
    }

    /**
     * Locks in the UI-level half of the fix: the "Also resolving: Follow-up"
     * dialog must never even offer the duplicate-causing outcome for the
     * destination it's paired with, while every other outcome (including
     * ones that create something genuinely different) stays available.
     */
    public function test_follow_up_stage_fields_exclude_the_outcome_that_would_duplicate_the_cross_drop_destination(): void
    {
        $board = app(PipelineBoard::class);
        $method = new \ReflectionMethod($board, 'followUpStageFields');
        $method->setAccessible(true);

        /** @var array<int, \Filament\Forms\Components\Component> $appointmentDestFields */
        $appointmentDestFields = $method->invoke($board, FollowUpStatus::Completed->value, null, 'source_', 'appointment');
        $outcomeField = $appointmentDestFields[0];
        $options = $outcomeField->getOptions();

        $this->assertArrayNotHasKey(CallOutcome::AppointmentSet->value, $options, 'Appointment Set must be excluded when the destination is Appointment.');
        $this->assertArrayHasKey(CallOutcome::CallbackRequested->value, $options, 'Unrelated outcomes must remain available.');
        $this->assertArrayHasKey(CallOutcome::RequirementIdentified->value, $options, 'Requirement Identified is only excluded for a Lead destination.');

        /** @var array<int, \Filament\Forms\Components\Component> $leadDestFields */
        $leadDestFields = $method->invoke($board, FollowUpStatus::Completed->value, null, 'source_', 'lead');
        $leadOptions = $leadDestFields[0]->getOptions();

        $this->assertArrayNotHasKey(CallOutcome::RequirementIdentified->value, $leadOptions, 'Requirement Identified must be excluded when the destination is Lead.');
        $this->assertArrayHasKey(CallOutcome::AppointmentSet->value, $leadOptions, 'Appointment Set is only excluded for an Appointment destination.');

        // The same-lane dialog (no destResource) must offer every outcome.
        $sameLaneFields = $method->invoke($board, FollowUpStatus::Completed->value, null, '', null);
        $sameLaneOptions = $sameLaneFields[0]->getOptions();

        $this->assertArrayHasKey(CallOutcome::AppointmentSet->value, $sameLaneOptions);
        $this->assertArrayHasKey(CallOutcome::RequirementIdentified->value, $sameLaneOptions);
    }
}
