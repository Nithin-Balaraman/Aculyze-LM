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

    /**
     * Lost-Lead Demo protection fix: a Lost Lead is a closed outcome (see
     * Lead::markLost()'s own docblock) and must never be offered as a
     * candidate for a brand-new Demo, mirroring the same guard Pipeline
     * Board's own Lead->Demo cross-drop already enforces.
     */
    public function test_the_lead_selector_excludes_a_lost_lead_for_the_same_prospect(): void
    {
        $user = User::factory()->create();
        $followUp = $this->makeFollowUp($user);
        $activeLead = Lead::create([
            'prospect_id' => $followUp->prospect_id,
            'assigned_to' => $user->id,
            'created_by' => $user->id,
            'stage' => 'requirement_collection',
            'status' => LeadStatus::RequirementCollection,
            'temperature' => 'warm',
        ]);
        $lostLead = Lead::create([
            'prospect_id' => $followUp->prospect_id,
            'assigned_to' => $user->id,
            'created_by' => $user->id,
            'stage' => 'requirement_collection',
            'status' => LeadStatus::RequirementCollection,
            'temperature' => 'cold',
        ]);
        $lostLead->markLost('Went with a competitor.');
        $this->actingAs($user);

        $html = Livewire::test(ListFollowUps::class)
            ->mountTableAction('scheduleDemo', $followUp)
            ->html();

        // The Select's options are a searchable field with no ->preload(),
        // so Filament embeds them as a JSON-encoded options array inside
        // the field's own Alpine x-data (`options: JSON.parse('[{...
        // "value":"1",...}]')`), with every double quote rendered as a
        // literal backslash-u-0022 escape sequence rather than a plain `"`
        // character — confirmed by inspecting the actual rendered markup.
        // Built via chr(92) rather than typed escape sequences so this
        // string is unambiguous regardless of any intermediate string
        // processing.
        $escapedQuote = chr(92).'u0022';
        $this->assertStringContainsString(
            $escapedQuote.'value'.$escapedQuote.':'.$escapedQuote.$activeLead->id.$escapedQuote,
            $html,
            'The active, non-lost Lead must still be offered.',
        );
        $this->assertStringNotContainsString(
            $escapedQuote.'value'.$escapedQuote.':'.$escapedQuote.$lostLead->id.$escapedQuote,
            $html,
            'A Lost Lead must never be offered as a Demo target.',
        );
    }

    public function test_scheduling_a_demo_against_a_lost_lead_is_rejected_even_if_its_id_is_submitted_directly(): void
    {
        $user = User::factory()->create();
        $followUp = $this->makeFollowUp($user);
        $lostLead = Lead::create([
            'prospect_id' => $followUp->prospect_id,
            'assigned_to' => $user->id,
            'created_by' => $user->id,
            'stage' => 'requirement_collection',
            'status' => LeadStatus::RequirementCollection,
            'temperature' => 'cold',
        ]);
        $lostLead->markLost('Went with a competitor.');
        $this->actingAs($user);

        // Bypasses the (now-filtered) dropdown entirely, exactly as a
        // stale client-side form state or a direct API call would —
        // proving the rejection is enforced by the service, not merely
        // by which options the UI happens to render.
        Livewire::test(ListFollowUps::class)
            ->callTableAction('scheduleDemo', $followUp, data: [
                'lead_id' => $lostLead->id,
                'demo_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
                'mode' => DemoMode::Online->value,
                'meeting_link' => 'https://meet.example.com/demo',
            ])
            ->assertNotified();

        $this->assertSame(0, Demo::count(), 'No Demo should be persisted when the target Lead is Lost.');
    }
}
