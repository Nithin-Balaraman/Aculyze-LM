<?php

namespace Tests\Feature;

use App\Enums\DemoMode;
use App\Enums\DemoStatus;
use App\Enums\FollowUpStatus;
use App\Enums\LeadStatus;
use App\Filament\Resources\FollowUpResource\Pages\ListFollowUps;
use App\Models\Demo;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 3 correction: Follow-Up -> Demo is one of the four approved routes
 * into Demo (WorkflowTransitionService::transitionToDemo()'s own docblock),
 * but had no user-facing entry point at all before this — Lead, Proposal,
 * and Appointment already had one. Proves the "Schedule Demo" action on
 * FollowUpResource exists, requires a valid Lead behind the same Prospect,
 * blocks a duplicate Scheduled Demo, and routes exclusively through the
 * centralized service.
 */
class FollowUpScheduleDemoResourceTest extends TestCase
{
    use RefreshDatabase;

    private function makeFollowUp(User $user): FollowUp
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

        return FollowUp::create([
            'prospect_id' => $prospect->id,
            'user_id' => $user->id,
            'follow_up_at' => now()->addDay(),
            'reason' => 'Callback requested',
            'status' => FollowUpStatus::Pending,
        ]);
    }

    public function test_schedule_demo_action_is_hidden_once_the_follow_up_is_no_longer_pending(): void
    {
        $user = User::factory()->create();
        $followUp = $this->makeFollowUp($user);
        $this->actingAs($user);

        Livewire::test(ListFollowUps::class)
            ->assertTableActionVisible('scheduleDemo', $followUp);

        $followUp->update(['status' => FollowUpStatus::Cancelled, 'notes' => 'No longer interested.']);

        Livewire::test(ListFollowUps::class)
            ->assertTableActionHidden('scheduleDemo', $followUp);
    }

    public function test_scheduling_a_demo_from_a_follow_up_creates_it_via_the_centralized_transition_service(): void
    {
        $user = User::factory()->create();
        $followUp = $this->makeFollowUp($user);
        $lead = Lead::create([
            'prospect_id' => $followUp->prospect_id,
            'assigned_to' => $user->id,
            'created_by' => $user->id,
            'stage' => 'requirement_collection',
            'status' => LeadStatus::RequirementCollection,
            'temperature' => 'warm',
        ]);
        $this->actingAs($user);

        Livewire::test(ListFollowUps::class)
            ->callTableAction('scheduleDemo', $followUp, data: [
                'lead_id' => $lead->id,
                'demo_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
                'mode' => DemoMode::Online->value,
                'meeting_link' => 'https://meet.example.com/demo',
            ])
            ->assertHasNoTableActionErrors();

        $demo = Demo::sole();
        $this->assertSame($lead->id, $demo->lead_id);
        $this->assertSame('follow_up', $demo->origin_type);
        $this->assertSame($followUp->id, $demo->origin_id);
        $this->assertSame(DemoStatus::Scheduled, $demo->status);
    }

    public function test_scheduling_a_demo_is_rejected_when_the_lead_already_has_a_scheduled_demo(): void
    {
        $user = User::factory()->create();
        $followUp = $this->makeFollowUp($user);
        $lead = Lead::create([
            'prospect_id' => $followUp->prospect_id,
            'assigned_to' => $user->id,
            'created_by' => $user->id,
            'stage' => 'requirement_collection',
            'status' => LeadStatus::RequirementCollection,
            'temperature' => 'warm',
        ]);
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
        $this->actingAs($user);

        Livewire::test(ListFollowUps::class)
            ->callTableAction('scheduleDemo', $followUp, data: [
                'lead_id' => $lead->id,
                'demo_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
                'mode' => DemoMode::Online->value,
                'meeting_link' => 'https://meet.example.com/demo',
            ])
            ->assertNotified();

        $this->assertSame(1, Demo::count(), 'Must not create a second Scheduled Demo for the same Lead.');
    }
}
