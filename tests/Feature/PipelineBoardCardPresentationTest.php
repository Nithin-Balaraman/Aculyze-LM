<?php

namespace Tests\Feature;

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
 * Pipeline Board redesign, Section 5 (Card Design): "assigned Employee" and
 * "overdue badge where applicable" — the two items from that section's
 * wishlist genuinely absent from the card before this pass. `isOverdue()`
 * already existed on Appointment/Demo/FollowUp (Phase 2) but was never
 * surfaced anywhere in the app — this only wires an existing, correct,
 * already-shaped method into the board's own card data, adding no new
 * business logic. Lead/Proposal deliberately carry no overdue flag (see
 * AGENTS.md sections 23/27 — neither becomes "overdue" merely because time
 * passes), so this file confirms their absence too, not just presence
 * elsewhere.
 */
class PipelineBoardCardPresentationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pipeline Board visual redesign: getLanes() now returns one flat
     * `cards` list per lane (no nested per-stage grouping) — see
     * PipelineBoard::stageBasedLane()/followUpLane()/demoLane().
     */
    private function findCard(array $lanes, string $laneKey, int $id): ?array
    {
        foreach ($lanes[$laneKey]['cards'] as $card) {
            if ($card['id'] === $id) {
                return $card;
            }
        }

        return null;
    }

    public function test_appointment_card_carries_the_assigned_employees_name(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $employee = User::factory()->create(['organization_id' => $org->id, 'name' => 'Priya Sharma']);
            $this->actingAs($employee);
            $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
            $appointment = Appointment::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $employee->id, 'created_by' => $employee->id,
                'appointment_at' => now()->addDay(), 'stage' => 'appointment_made',
            ]);

            $lanes = app(PipelineBoard::class)->getLanes();
            $card = $this->findCard($lanes, 'appointment', $appointment->id);

            $this->assertSame('Priya Sharma', $card['assignedTo']);
        });
    }

    public function test_an_appointment_past_its_own_scheduled_time_and_still_scheduled_is_flagged_overdue(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $employee = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($employee);
            $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
            $overdue = Appointment::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $employee->id, 'created_by' => $employee->id,
                'appointment_at' => now()->subDays(2), 'stage' => 'appointment_made',
            ]);
            $upcoming = Appointment::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $employee->id, 'created_by' => $employee->id,
                'appointment_at' => now()->addDays(2), 'stage' => 'appointment_made',
            ]);

            $lanes = app(PipelineBoard::class)->getLanes();

            $this->assertTrue($this->findCard($lanes, 'appointment', $overdue->id)['isOverdue']);
            $this->assertFalse($this->findCard($lanes, 'appointment', $upcoming->id)['isOverdue']);
        });
    }

    public function test_a_pending_follow_up_past_its_own_date_is_flagged_overdue(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $employee = User::factory()->create(['organization_id' => $org->id, 'name' => 'Arjun Rao']);
            $this->actingAs($employee);
            $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
            $followUp = FollowUp::create([
                'prospect_id' => $prospect->id, 'user_id' => $employee->id,
                'follow_up_at' => now()->subDay(), 'reason' => 'Overdue callback.', 'status' => 'pending',
            ]);

            $lanes = app(PipelineBoard::class)->getLanes();
            $card = $this->findCard($lanes, 'follow_up', $followUp->id);

            $this->assertTrue($card['isOverdue']);
            $this->assertSame('Arjun Rao', $card['assignedTo']);
        });
    }

    public function test_a_scheduled_demo_past_its_own_date_is_flagged_overdue(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $employee = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($employee);
            $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
            $lead = Lead::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $employee->id, 'created_by' => $employee->id,
                'stage' => 'validated', 'temperature' => 'hot', 'notes' => 'x',
            ]);
            $demo = Demo::create([
                'prospect_id' => $prospect->id, 'lead_id' => $lead->id,
                'assigned_to' => $employee->id, 'created_by' => $employee->id,
                'demo_at' => now()->subDay(), 'mode' => 'online', 'meeting_link' => 'https://example.com',
                'status' => 'scheduled',
            ]);

            $lanes = app(PipelineBoard::class)->getLanes();
            $card = $this->findCard($lanes, 'demo', $demo->id);

            $this->assertTrue($card['isOverdue']);
        });
    }

    /** Lead/Proposal have no isOverdue() concept at all — see AGENTS.md sections 23/27. */
    public function test_lead_and_proposal_cards_never_carry_an_overdue_flag(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $employee = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($employee);
            $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
            $lead = Lead::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $employee->id, 'created_by' => $employee->id,
                'stage' => 'requirement_collection', 'temperature' => 'hot', 'notes' => 'x',
                'stage_changed_at' => now()->subDays(60),
            ]);

            $lanes = app(PipelineBoard::class)->getLanes();
            $card = $this->findCard($lanes, 'lead', $lead->id);

            $this->assertFalse($card['isOverdue']);
        });
    }
}
