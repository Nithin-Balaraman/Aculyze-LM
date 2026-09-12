<?php

namespace Tests\Feature;

use App\Enums\AppointmentStage;
use App\Enums\AppointmentStatus;
use App\Enums\DemoStatus;
use App\Enums\FollowUpStatus;
use App\Enums\LeadStage;
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
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pipeline Board visual redesign (presentation-only — see AGENTS.md's own
 * "Pipeline Board visual redesign" section): every lane is now a single
 * flat Kanban column, never a lane made of nested per-stage/status boxes.
 * getLanes() itself only ever returns `['label' => ..., 'cards' => [...]]`
 * per lane any more — there is no `stages` key left anywhere in its output
 * — and the record's own internal stage/status travels on the card itself
 * (`stageValue`/`stageLabel`) as a badge, not as a separate visual
 * container. This file locks in the required assertions from the
 * redesign's own test list (Section 14) that no other file already
 * covers; every workflow/drag/authorization/tenancy/safety test in the
 * existing suite passes completely unchanged (see PipelineBoard*Test.php
 * elsewhere) — this file is presentation-shape only.
 */
class PipelineBoardFlatLaneStructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_board_has_exactly_six_lanes_in_the_required_order_with_no_seventh_proposal_sent_lane(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);

            $lanes = app(PipelineBoard::class)->getLanes();

            $this->assertSame(
                ['call', 'follow_up', 'appointment', 'lead', 'demo', 'proposal'],
                array_keys($lanes),
                'Exactly 6 lanes, in this exact order — Demo present, no seventh Proposal Sent lane.'
            );
            $this->assertArrayNotHasKey('proposal_sent', $lanes);
        });
    }

    /**
     * getLanes() must never again return a `stages` key on any lane — the
     * whole point of the redesign is that nested per-stage/status
     * containers no longer exist, only one flat `cards` list per lane.
     */
    public function test_no_lane_carries_a_nested_stages_key_any_more(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);

            $lanes = app(PipelineBoard::class)->getLanes();

            foreach ($lanes as $laneKey => $lane) {
                $this->assertArrayNotHasKey('stages', $lane, "Lane '{$laneKey}' must not carry a nested stages grouping.");
                $this->assertArrayHasKey('cards', $lane, "Lane '{$laneKey}' must carry one flat cards list.");
                $this->assertIsArray($lane['cards']);
            }
        });
    }

    public function test_appointment_records_with_different_internal_statuses_all_appear_in_the_one_appointments_lane(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

            $made = Appointment::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'appointment_at' => now()->addDay(), 'stage' => AppointmentStage::AppointmentMade,
            ]);
            // VisitConducted/DiscussionCompleted are NOT normalized business
            // outcomes — status legitimately stays Scheduled through both
            // (see Appointment::booted()'s own docblock and
            // PipelineBoard::dropAppointment(), which only ever touches
            // `stage` for these two, never `status`) — this is exactly what
            // a real same-lane board drag produces, unlike a bare
            // Appointment::create() with no status, which would trigger the
            // insert-only legacy-compatibility fallback and derive
            // status=Completed instead (a genuinely historical record, off
            // the active board by design — see excludingHistoricalStatus()).
            $visited = Appointment::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'appointment_at' => now()->addDay(), 'stage' => AppointmentStage::VisitConducted,
                'status' => AppointmentStatus::Scheduled,
            ]);
            $discussed = Appointment::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'appointment_at' => now()->addDay(), 'stage' => AppointmentStage::DiscussionCompleted,
                'status' => AppointmentStatus::Scheduled,
            ]);

            $lanes = app(PipelineBoard::class)->getLanes();
            $cards = collect($lanes['appointment']['cards']);

            $this->assertCount(1, $cards->where('id', $made->id));
            $this->assertCount(1, $cards->where('id', $visited->id));
            $this->assertCount(1, $cards->where('id', $discussed->id));
            $this->assertSame('Appointment Made', $cards->firstWhere('id', $made->id)['stageLabel']);
            $this->assertSame('Visit / Meeting Conducted', $cards->firstWhere('id', $visited->id)['stageLabel']);
            $this->assertSame('Discussion Completed', $cards->firstWhere('id', $discussed->id)['stageLabel']);
        });
    }

    public function test_lead_records_with_different_internal_statuses_all_appear_in_the_one_leads_lane(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

            $collecting = Lead::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'stage' => LeadStage::RequirementCollection, 'temperature' => 'warm',
            ]);
            $demoStage = Lead::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'stage' => LeadStage::DemoScheduledOrDone, 'temperature' => 'warm',
            ]);
            $validated = Lead::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'stage' => LeadStage::Validated, 'temperature' => 'hot', 'notes' => 'Confirmed requirement.',
            ]);

            $lanes = app(PipelineBoard::class)->getLanes();
            $cards = collect($lanes['lead']['cards']);

            $this->assertCount(1, $cards->where('id', $collecting->id));
            $this->assertCount(1, $cards->where('id', $demoStage->id));
            $this->assertCount(1, $cards->where('id', $validated->id));
            $this->assertSame(LeadStage::RequirementCollection->value, $cards->firstWhere('id', $collecting->id)['stageValue']);
            $this->assertSame(LeadStage::DemoScheduledOrDone->value, $cards->firstWhere('id', $demoStage->id)['stageValue']);
            $this->assertSame(LeadStage::Validated->value, $cards->firstWhere('id', $validated->id)['stageValue']);
        });
    }

    public function test_demo_records_with_different_internal_statuses_all_appear_in_the_one_demo_lane(): void
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

            $scheduled = Demo::create([
                'prospect_id' => $prospect->id, 'lead_id' => $lead->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'demo_at' => now()->addDay(), 'mode' => 'online', 'meeting_link' => 'https://example.com', 'status' => DemoStatus::Scheduled,
            ]);
            $completed = Demo::create([
                'prospect_id' => $prospect->id, 'lead_id' => $lead->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'demo_at' => now()->subDay(), 'mode' => 'online', 'meeting_link' => 'https://example.com', 'status' => DemoStatus::Completed,
            ]);
            $cancelled = Demo::create([
                'prospect_id' => $prospect->id, 'lead_id' => $lead->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'demo_at' => now()->addDays(2), 'mode' => 'online', 'meeting_link' => 'https://example.com', 'status' => DemoStatus::Cancelled,
            ]);

            $lanes = app(PipelineBoard::class)->getLanes();
            $cards = collect($lanes['demo']['cards']);

            $this->assertCount(1, $cards->where('id', $scheduled->id));
            $this->assertCount(1, $cards->where('id', $completed->id));
            $this->assertCount(1, $cards->where('id', $cancelled->id));
            $this->assertSame('Scheduled', $cards->firstWhere('id', $scheduled->id)['stageLabel']);
            $this->assertSame('Completed', $cards->firstWhere('id', $completed->id)['stageLabel']);
            $this->assertSame('Cancelled', $cards->firstWhere('id', $cancelled->id)['stageLabel']);
        });
    }

    public function test_follow_up_records_with_different_statuses_do_not_create_separate_lane_containers(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

            $pending = FollowUp::create([
                'prospect_id' => $prospect->id, 'user_id' => $user->id, 'follow_up_at' => now()->addDay(),
                'reason' => 'Awaiting response.', 'status' => FollowUpStatus::Pending,
            ]);
            $completed = FollowUp::create([
                'prospect_id' => $prospect->id, 'user_id' => $user->id, 'follow_up_at' => now()->subDay(),
                'reason' => 'Closed the call.', 'status' => FollowUpStatus::Completed,
            ]);
            $cancelled = FollowUp::create([
                'prospect_id' => $prospect->id, 'user_id' => $user->id, 'follow_up_at' => now()->addDays(2),
                'reason' => 'No longer needed.', 'status' => FollowUpStatus::Cancelled,
            ]);

            $lanes = app(PipelineBoard::class)->getLanes();

            $this->assertArrayNotHasKey('stages', $lanes['follow_up']);
            $cards = collect($lanes['follow_up']['cards']);
            $this->assertCount(1, $cards->where('id', $pending->id));
            $this->assertCount(1, $cards->where('id', $completed->id));
            $this->assertCount(1, $cards->where('id', $cancelled->id));
        });
    }

    public function test_lane_counts_reflect_the_number_of_cards_actually_shown(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

            Lead::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'stage' => 'requirement_collection', 'temperature' => 'warm',
            ]);
            Lead::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'stage' => 'requirement_collection', 'temperature' => 'hot',
            ]);

            $lanes = app(PipelineBoard::class)->getLanes();

            $this->assertCount(2, $lanes['lead']['cards']);
            $this->assertSame(0, count($lanes['call']['cards']));
        });
    }

    /**
     * The rendered board must contain no leftover per-stage drop-target
     * markers at all (`data-stage` was the old stage-box's own attribute —
     * see the now-deleted pipeline-board-stage-box.blade.php) — only
     * `data-lane` on the one flat drop zone per lane.
     */
    public function test_rendered_board_html_contains_no_nested_stage_group_containers(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);

            $html = Livewire::test(PipelineBoard::class)->html();

            $this->assertStringNotContainsString('data-stage', $html);
            $this->assertMatchesRegularExpression('/data-lane="call"/', $html);
            $this->assertMatchesRegularExpression('/data-lane="proposal"/', $html);
        });
    }

    /** An empty lane shows exactly one clean empty state, not one per removed stage box. */
    public function test_an_empty_lane_shows_a_single_clean_empty_state(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);

            $html = Livewire::test(PipelineBoard::class)->html();

            $this->assertSame(6, substr_count($html, 'No Opportunities'), 'Exactly one empty-state message per empty lane — never a per-removed-stage-box repeat.');
        });
    }
}
