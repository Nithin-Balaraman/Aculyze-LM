<?php

namespace Tests\Feature;

use App\Enums\AppointmentOutcome;
use App\Enums\AppointmentStage;
use App\Enums\AppointmentStatus;
use App\Enums\DemoMode;
use App\Filament\Resources\AppointmentResource\Pages\ListAppointments;
use App\Models\Appointment;
use App\Models\Demo;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 3 correction: AppointmentResource previously had NO user-facing
 * Record Outcome action at all — every Appointment outcome had to be
 * simulated by calling WorkflowTransitionService::transitionAppointmentOutcome()
 * directly in tests, with no real UI path to reach it. This proves the
 * action exists, is wired to the centralized service (never a raw
 * stage/status mutation), and surfaces each approved outcome correctly.
 */
class AppointmentRecordOutcomeResourceTest extends TestCase
{
    use RefreshDatabase;

    private function makeAppointment(User $user): Appointment
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

        return Appointment::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $user->id,
            'created_by' => $user->id,
            'appointment_at' => now()->addDays(2),
            'stage' => AppointmentStage::AppointmentMade,
            'status' => AppointmentStatus::Scheduled,
        ]);
    }

    public function test_record_outcome_action_is_hidden_once_the_appointment_is_no_longer_scheduled(): void
    {
        $user = User::factory()->create();
        $appointment = $this->makeAppointment($user);
        $this->actingAs($user);

        Livewire::test(ListAppointments::class)
            ->assertTableActionVisible('recordOutcome', $appointment);

        $appointment->update(['status' => AppointmentStatus::Cancelled]);

        Livewire::test(ListAppointments::class)
            ->assertTableActionHidden('recordOutcome', $appointment);
    }

    public function test_recording_follow_up_required_creates_exactly_one_follow_up_via_the_service(): void
    {
        $user = User::factory()->create();
        $appointment = $this->makeAppointment($user);
        $this->actingAs($user);

        Livewire::test(ListAppointments::class)
            ->callTableAction('recordOutcome', $appointment, data: [
                'outcome' => AppointmentOutcome::FollowUpRequired->value,
                'outcome_notes' => 'Needs to check budget internally.',
                'follow_up_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
            ])
            ->assertHasNoTableActionErrors();

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::Completed, $appointment->status);
        $this->assertSame(AppointmentOutcome::FollowUpRequired, $appointment->outcome);
        $this->assertSame(1, FollowUp::count());
    }

    public function test_recording_requirement_identified_creates_exactly_one_lead(): void
    {
        $user = User::factory()->create();
        $appointment = $this->makeAppointment($user);
        $this->actingAs($user);

        Livewire::test(ListAppointments::class)
            ->callTableAction('recordOutcome', $appointment, data: [
                'outcome' => AppointmentOutcome::RequirementIdentified->value,
                'outcome_notes' => 'A real requirement came up.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, Lead::count());
    }

    public function test_recording_demo_required_without_a_lead_id_is_rejected_by_form_validation(): void
    {
        $user = User::factory()->create();
        $appointment = $this->makeAppointment($user);
        $this->actingAs($user);

        Livewire::test(ListAppointments::class)
            ->callTableAction('recordOutcome', $appointment, data: [
                'outcome' => AppointmentOutcome::DemoRequired->value,
                'outcome_notes' => 'Wants to see the product first.',
                'demo_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
                'mode' => DemoMode::Online->value,
                'meeting_link' => 'https://meet.example.com/demo',
            ])
            ->assertHasTableActionErrors(['lead_id' => 'required']);

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::Scheduled, $appointment->status, 'Rejected transition must leave the Appointment untouched.');
        $this->assertSame(0, Demo::count());
    }

    public function test_recording_demo_required_with_a_valid_lead_creates_the_demo(): void
    {
        $user = User::factory()->create();
        $appointment = $this->makeAppointment($user);
        $lead = Lead::create([
            'prospect_id' => $appointment->prospect_id,
            'assigned_to' => $user->id,
            'created_by' => $user->id,
            'stage' => 'requirement_collection',
            'status' => \App\Enums\LeadStatus::RequirementCollection,
            'temperature' => 'warm',
        ]);
        $this->actingAs($user);

        Livewire::test(ListAppointments::class)
            ->callTableAction('recordOutcome', $appointment, data: [
                'outcome' => AppointmentOutcome::DemoRequired->value,
                'outcome_notes' => 'Wants to see the product first.',
                'lead_id' => $lead->id,
                'demo_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
                'mode' => DemoMode::Online->value,
                'meeting_link' => 'https://meet.example.com/demo',
            ])
            ->assertHasNoTableActionErrors();

        $demo = Demo::sole();
        $this->assertSame($lead->id, $demo->lead_id);
        $this->assertSame('appointment', $demo->origin_type);
    }

    public function test_no_current_requirement_creates_no_downstream_record(): void
    {
        $user = User::factory()->create();
        $appointment = $this->makeAppointment($user);
        $this->actingAs($user);

        Livewire::test(ListAppointments::class)
            ->callTableAction('recordOutcome', $appointment, data: [
                'outcome' => AppointmentOutcome::NoCurrentRequirement->value,
                'outcome_notes' => 'No budget this cycle.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(0, FollowUp::count());
        $this->assertSame(0, Lead::count());
        $this->assertSame(0, Demo::count());
    }
}
