<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Models\Lead;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 3 correction: LeadResource previously had NO user-facing action
 * exposing WorkflowTransitionService::transitionLeadStatus() at all —
 * normal Lead status changes had no real UI path, only the backend
 * service. This proves the Update Status action exists, routes through
 * the centralized service, leaves legacy `stage` untouched, and enforces
 * the same Notes-required rule the model itself guards.
 */
class LeadUpdateStatusResourceTest extends TestCase
{
    use RefreshDatabase;

    private function makeLead(User $user): Lead
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

        return Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $user->id,
            'created_by' => $user->id,
            'stage' => LeadStage::RequirementCollection,
            'status' => LeadStatus::RequirementCollection,
            'temperature' => 'warm',
        ]);
    }

    public function test_update_status_action_is_hidden_for_a_lost_lead(): void
    {
        $user = User::factory()->create();
        $lead = $this->makeLead($user);
        $this->actingAs($user);

        Livewire::test(ListLeads::class)
            ->assertTableActionVisible('updateStatus', $lead);

        $lead->markLost('No longer interested.');

        Livewire::test(ListLeads::class)
            ->assertTableActionHidden('updateStatus', $lead);
    }

    public function test_update_status_changes_status_and_leaves_legacy_stage_untouched(): void
    {
        $user = User::factory()->create();
        $lead = $this->makeLead($user);
        $this->actingAs($user);

        Livewire::test(ListLeads::class)
            ->callTableAction('updateStatus', $lead, data: [
                'status' => LeadStatus::FollowUpRequired->value,
                'notes' => 'Waiting on their internal budget approval.',
            ])
            ->assertHasNoTableActionErrors();

        $lead->refresh();
        $this->assertSame(LeadStatus::FollowUpRequired, $lead->status);
        $this->assertSame(LeadStage::RequirementCollection, $lead->stage, 'Update Status must never touch legacy stage.');
    }

    public function test_moving_status_to_proposal_required_without_notes_is_rejected(): void
    {
        $user = User::factory()->create();
        $lead = $this->makeLead($user);
        $this->actingAs($user);

        Livewire::test(ListLeads::class)
            ->callTableAction('updateStatus', $lead, data: [
                'status' => LeadStatus::ProposalRequired->value,
                'notes' => '',
            ])
            ->assertHasTableActionErrors(['notes' => 'required']);

        $this->assertSame(LeadStatus::RequirementCollection, $lead->fresh()->status, 'Rejected transition must leave the Lead untouched.');
    }

    public function test_moving_status_to_proposal_required_with_notes_succeeds(): void
    {
        $user = User::factory()->create();
        $lead = $this->makeLead($user);
        $this->actingAs($user);

        Livewire::test(ListLeads::class)
            ->callTableAction('updateStatus', $lead, data: [
                'status' => LeadStatus::ProposalRequired->value,
                'notes' => 'Confirmed budget and requirement.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(LeadStatus::ProposalRequired, $lead->fresh()->status);
    }
}
