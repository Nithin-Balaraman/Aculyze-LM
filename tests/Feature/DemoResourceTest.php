<?php

namespace Tests\Feature;

use App\Enums\DemoMode;
use App\Enums\DemoOutcome;
use App\Enums\DemoStatus;
use App\Enums\LeadStatus;
use App\Filament\Resources\DemoResource;
use App\Filament\Resources\DemoResource\Pages\ListDemos;
use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 3: Demo is first-class, user-facing functionality — but every Demo
 * belongs to an existing Lead and is created only through a valid workflow
 * transition. DemoResource itself has NO 'create' route/page at all.
 */
class DemoResourceTest extends TestCase
{
    use RefreshDatabase;

    private function makeLead(User $owner): Lead
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);

        return Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'stage' => 'requirement_collection',
            'status' => LeadStatus::RequirementCollection,
            'temperature' => 'warm',
        ]);
    }

    public function test_demo_resource_has_no_create_route(): void
    {
        $this->assertFalse(DemoResource::hasPage('create'));
    }

    public function test_scheduling_a_demo_from_a_lead_creates_it_via_the_centralized_transition_service(): void
    {
        $employee = User::factory()->create();
        $lead = $this->makeLead($employee);
        $this->actingAs($employee);

        Livewire::test(ListLeads::class)
            ->callTableAction('scheduleDemo', $lead, data: [
                'demo_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
                'mode' => DemoMode::Online->value,
                'meeting_link' => 'https://meet.example.com/demo',
            ])
            ->assertHasNoTableActionErrors();

        $demo = Demo::sole();
        $this->assertSame($lead->id, $demo->lead_id);
        $this->assertSame('lead', $demo->origin_type);
        $this->assertSame($lead->id, $demo->origin_id);
        $this->assertSame(DemoStatus::Scheduled, $demo->status);
    }

    public function test_schedule_demo_is_hidden_once_a_scheduled_demo_already_exists(): void
    {
        $employee = User::factory()->create();
        $lead = $this->makeLead($employee);
        $this->actingAs($employee);

        Livewire::test(ListLeads::class)
            ->assertTableActionVisible('scheduleDemo', $lead);

        Livewire::test(ListLeads::class)
            ->callTableAction('scheduleDemo', $lead, data: [
                'demo_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
                'mode' => DemoMode::Online->value,
                'meeting_link' => 'https://meet.example.com/demo',
            ]);

        Livewire::test(ListLeads::class)
            ->assertTableActionHidden('scheduleDemo', $lead);
    }

    public function test_pending_and_history_tabs_split_by_normalized_status_only(): void
    {
        $employee = User::factory()->create();
        $lead = $this->makeLead($employee);

        $scheduled = Demo::create([
            'prospect_id' => $lead->prospect_id,
            'lead_id' => $lead->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'demo_at' => now()->addDays(2),
            'mode' => DemoMode::Online,
            'meeting_link' => 'https://meet.example.com/a',
            'status' => DemoStatus::Scheduled,
        ]);

        $completed = Demo::create([
            'prospect_id' => $lead->prospect_id,
            'lead_id' => $lead->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'demo_at' => now()->subDays(2),
            'mode' => DemoMode::Online,
            'meeting_link' => 'https://meet.example.com/b',
            'status' => DemoStatus::Completed,
            'outcome' => DemoOutcome::NotInterestedNoProgression,
            'next_action' => \App\Enums\DemoNextAction::NoFurtherAction,
        ]);

        $this->actingAs($employee);

        Livewire::test(ListDemos::class)
            ->set('activeTab', 'pending')
            ->assertCanSeeTableRecords([$scheduled])
            ->assertCanNotSeeTableRecords([$completed]);

        Livewire::test(ListDemos::class)
            ->set('activeTab', 'history')
            ->assertCanSeeTableRecords([$completed])
            ->assertCanNotSeeTableRecords([$scheduled]);
    }

    public function test_reschedule_preserves_the_old_demo_and_creates_a_distinct_active_replacement(): void
    {
        $employee = User::factory()->create();
        $lead = $this->makeLead($employee);
        $original = Demo::create([
            'prospect_id' => $lead->prospect_id,
            'lead_id' => $lead->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'demo_at' => now()->addDays(2),
            'mode' => DemoMode::Online,
            'meeting_link' => 'https://meet.example.com/a',
            'status' => DemoStatus::Scheduled,
        ]);

        $this->actingAs($employee);
        $newDemoAt = now()->addDays(5)->startOfMinute();

        Livewire::test(ListDemos::class)
            ->callTableAction('reschedule', $original, data: [
                'demo_at' => $newDemoAt->format('Y-m-d H:i:s'),
                'reason' => 'Customer asked to push it out.',
            ])
            ->assertHasNoTableActionErrors();

        $original->refresh();
        $this->assertSame(DemoStatus::Rescheduled, $original->status);

        $replacement = Demo::query()->where('id', '!=', $original->id)->sole();
        $this->assertSame(DemoStatus::Scheduled, $replacement->status);
        $this->assertSame($original->id, $replacement->rescheduled_from_id);
        $this->assertTrue($newDemoAt->equalTo($replacement->demo_at));
    }

    /**
     * Deterministic outcome (Proposal Required) auto-sets next_action and
     * creates the Proposal — the full approved determinism table is
     * enforced by WorkflowTransitionService itself; this proves the UI
     * wiring surfaces it correctly.
     */
    public function test_recording_a_deterministic_outcome_creates_the_expected_downstream_record(): void
    {
        $employee = User::factory()->create();
        $lead = $this->makeLead($employee);
        $demo = Demo::create([
            'prospect_id' => $lead->prospect_id,
            'lead_id' => $lead->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'demo_at' => now()->addDays(2),
            'mode' => DemoMode::Online,
            'meeting_link' => 'https://meet.example.com/a',
            'status' => DemoStatus::Scheduled,
        ]);

        $this->actingAs($employee);

        Livewire::test(ListDemos::class)
            ->callTableAction('recordOutcome', $demo, data: [
                'outcome' => DemoOutcome::ProposalRequired->value,
            ])
            ->assertHasNoTableActionErrors();

        $demo->refresh();
        $this->assertSame(DemoStatus::Completed, $demo->status);
        $this->assertSame(DemoOutcome::ProposalRequired, $demo->outcome);
        $this->assertSame(\App\Enums\DemoNextAction::StartProposal, $demo->next_action);
        $this->assertSame(1, Proposal::where('lead_id', $lead->id)->count());
    }

    /**
     * Non-deterministic outcome (Interested/OK) does NOT silently create a
     * Proposal without the user explicitly confirming StartProposal.
     */
    public function test_interested_ok_does_not_silently_create_a_proposal_without_a_confirmed_start_proposal(): void
    {
        $employee = User::factory()->create();
        $lead = $this->makeLead($employee);
        $demo = Demo::create([
            'prospect_id' => $lead->prospect_id,
            'lead_id' => $lead->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'demo_at' => now()->addDays(2),
            'mode' => DemoMode::Online,
            'meeting_link' => 'https://meet.example.com/a',
            'status' => DemoStatus::Scheduled,
        ]);

        $this->actingAs($employee);

        Livewire::test(ListDemos::class)
            ->callTableAction('recordOutcome', $demo, data: [
                'outcome' => DemoOutcome::InterestedOk->value,
                'next_action' => \App\Enums\DemoNextAction::CreateFollowUp->value,
                'follow_up_at' => now()->addDays(4)->format('Y-m-d H:i:s'),
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(0, Proposal::count());
        $this->assertSame(1, \App\Models\FollowUp::count());
    }

    public function test_not_interested_creates_no_downstream_activity(): void
    {
        $employee = User::factory()->create();
        $lead = $this->makeLead($employee);
        $demo = Demo::create([
            'prospect_id' => $lead->prospect_id,
            'lead_id' => $lead->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'demo_at' => now()->addDays(2),
            'mode' => DemoMode::Online,
            'meeting_link' => 'https://meet.example.com/a',
            'status' => DemoStatus::Scheduled,
        ]);

        $this->actingAs($employee);

        Livewire::test(ListDemos::class)
            ->callTableAction('recordOutcome', $demo, data: [
                'outcome' => DemoOutcome::NotInterestedNoProgression->value,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(0, Proposal::count());
        $this->assertSame(0, \App\Models\FollowUp::count());
        $this->assertSame(1, Lead::count());
    }
}
