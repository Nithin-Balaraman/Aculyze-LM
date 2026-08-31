<?php

namespace Tests\Feature;

use App\Enums\DemoMode;
use App\Enums\DemoNextAction;
use App\Enums\DemoOutcome;
use App\Enums\DemoStatus;
use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Services\RescheduleService;
use App\Services\WorkflowTransitionService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Phase 2: Demo domain/model foundation — organization/hierarchy security,
 * one-Lead-hasMany-Demos, mode-specific field requirements, reschedule vs.
 * repeat-activity distinction, lineage stability, and the full
 * DemoOutcome -> DemoNextAction determinism table.
 */
class DemoModelTest extends TestCase
{
    use RefreshDatabase;

    private function org(): Organization
    {
        return Organization::factory()->create();
    }

    private function userIn(Organization $org, UserRole $role): User
    {
        return Tenancy::runAs($org->id, fn () => User::factory()->create(['organization_id' => $org->id, 'role' => $role]));
    }

    private function leadIn(Organization $org, User $user): Lead
    {
        return Tenancy::runAs($org->id, function () use ($user) {
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

            return Lead::create([
                'prospect_id' => $prospect->id,
                'assigned_to' => $user->id,
                'created_by' => $user->id,
                'stage' => LeadStage::RequirementCollection,
                'status' => LeadStatus::RequirementConfirmed,
                'temperature' => LeadTemperature::Warm,
                'notes' => 'Confirmed requirement.',
            ]);
        });
    }

    private function demoFor(Lead $lead, User $user, array $overrides = []): Demo
    {
        return Demo::factory()->create(array_merge([
            'lead_id' => $lead->id,
            'prospect_id' => $lead->prospect_id,
            'assigned_to' => $user->id,
            'created_by' => $user->id,
        ], $overrides));
    }

    // --- Security / tenancy / hierarchy ---

    public function test_demo_belongs_to_the_same_organization_as_its_lead_prospect_and_users(): void
    {
        $org = $this->org();
        $user = $this->userIn($org, UserRole::Employee);
        $lead = $this->leadIn($org, $user);

        $demo = Tenancy::runAs($org->id, fn () => $this->demoFor($lead, $user));

        $this->assertSame($org->id, $demo->organization_id);
        $this->assertSame($org->id, $lead->organization_id);
    }

    public function test_cross_organization_lead_reference_is_rejected(): void
    {
        $orgA = $this->org();
        $orgB = $this->org();
        $userA = $this->userIn($orgA, UserRole::Employee);
        $userB = $this->userIn($orgB, UserRole::Employee);
        $leadB = $this->leadIn($orgB, $userB);

        $this->expectException(\RuntimeException::class);

        Tenancy::runAs($orgA->id, fn () => $this->demoFor($leadB, $userA));
    }

    public function test_one_lead_can_have_multiple_demo_records(): void
    {
        $org = $this->org();
        $user = $this->userIn($org, UserRole::Employee);
        $lead = $this->leadIn($org, $user);

        Tenancy::runAs($org->id, function () use ($lead, $user) {
            $this->demoFor($lead, $user);
            $this->demoFor($lead, $user);
        });

        $this->assertSame(2, Demo::withoutGlobalScopes()->where('lead_id', $lead->id)->count());
    }

    public function test_demo_hierarchy_visibility_matches_employee_manager_senior_manager_rules(): void
    {
        $org = $this->org();
        $senior = $this->userIn($org, UserRole::Admin);
        $manager = $this->userIn($org, UserRole::Manager);
        $employee = Tenancy::runAs($org->id, fn () => User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Employee, 'manager_id' => $manager->id]));
        $otherEmployee = $this->userIn($org, UserRole::Employee);

        $lead = $this->leadIn($org, $employee);
        $demo = Tenancy::runAs($org->id, fn () => $this->demoFor($lead, $employee));

        Tenancy::runAs($org->id, function () use ($demo, $senior, $manager, $employee, $otherEmployee) {
            $this->assertTrue(Demo::query()->visibleTo($senior)->whereKey($demo->id)->exists());
            $this->assertTrue(Demo::query()->visibleTo($manager)->whereKey($demo->id)->exists());
            $this->assertTrue(Demo::query()->visibleTo($employee)->whereKey($demo->id)->exists());
            $this->assertFalse(Demo::query()->visibleTo($otherEmployee)->whereKey($demo->id)->exists());
        });
    }

    // --- Mode-specific field requirements ---

    public function test_on_site_demo_requires_a_location(): void
    {
        $org = $this->org();
        $user = $this->userIn($org, UserRole::Employee);
        $lead = $this->leadIn($org, $user);

        $this->expectException(LogicException::class);

        Tenancy::runAs($org->id, fn () => $this->demoFor($lead, $user, ['mode' => DemoMode::OnSite, 'location' => null]));
    }

    public function test_online_demo_requires_a_meeting_link(): void
    {
        $org = $this->org();
        $user = $this->userIn($org, UserRole::Employee);
        $lead = $this->leadIn($org, $user);

        $this->expectException(LogicException::class);

        Tenancy::runAs($org->id, fn () => $this->demoFor($lead, $user, ['mode' => DemoMode::Online, 'meeting_link' => null, 'location' => null]));
    }

    // --- Reschedule vs. repeat-activity distinction ---

    public function test_explicit_reschedule_of_an_uncompleted_demo_marks_it_rescheduled_and_links_via_rescheduled_from_id(): void
    {
        $org = $this->org();
        $user = $this->userIn($org, UserRole::Employee);
        $lead = $this->leadIn($org, $user);
        $demo = Tenancy::runAs($org->id, fn () => $this->demoFor($lead, $user));

        $replacement = Tenancy::runAs($org->id, fn () => app(RescheduleService::class)->reschedule(
            $demo, ['demo_at' => now()->addDays(6)]
        ));

        $this->assertSame(DemoStatus::Rescheduled, $demo->fresh()->status);
        $this->assertSame(DemoStatus::Scheduled, $replacement->status);
        $this->assertSame($demo->id, $replacement->rescheduled_from_id);
        $this->assertNull($replacement->origin_id);
    }

    public function test_another_demo_required_marks_the_old_demo_completed_not_rescheduled(): void
    {
        $org = $this->org();
        $user = $this->userIn($org, UserRole::Employee);
        $lead = $this->leadIn($org, $user);
        $demo = Tenancy::runAs($org->id, fn () => $this->demoFor($lead, $user));

        Tenancy::runAs($org->id, fn () => app(WorkflowTransitionService::class)->transitionDemoOutcome(
            $demo, DemoOutcome::AnotherDemoRequired, ['demo_at' => now()->addDays(7)]
        ));

        $demo->refresh();
        $this->assertSame(DemoStatus::Completed, $demo->status);
        $this->assertNotSame(DemoStatus::Rescheduled, $demo->status);
        $this->assertSame(DemoOutcome::AnotherDemoRequired, $demo->outcome);
        $this->assertSame(DemoNextAction::ScheduleAnotherDemo, $demo->next_action);
    }

    public function test_another_demo_required_creates_a_new_scheduled_demo_linked_via_lineage_not_reschedule(): void
    {
        $org = $this->org();
        $user = $this->userIn($org, UserRole::Employee);
        $lead = $this->leadIn($org, $user);
        $demo = Tenancy::runAs($org->id, fn () => $this->demoFor($lead, $user));

        Tenancy::runAs($org->id, fn () => app(WorkflowTransitionService::class)->transitionDemoOutcome(
            $demo, DemoOutcome::AnotherDemoRequired, ['demo_at' => now()->addDays(7)]
        ));

        $new = Demo::withoutGlobalScopes()->where('lead_id', $lead->id)->where('id', '!=', $demo->id)->firstOrFail();

        $this->assertSame(DemoStatus::Scheduled, $new->status);
        $this->assertNull($new->rescheduled_from_id);
        $this->assertSame('demo', $new->origin_type);
        $this->assertSame($demo->id, $new->origin_id);
    }

    public function test_interested_ok_with_schedule_another_demo_follows_the_same_completed_then_new_demo_pattern(): void
    {
        $org = $this->org();
        $user = $this->userIn($org, UserRole::Employee);
        $lead = $this->leadIn($org, $user);
        $demo = Tenancy::runAs($org->id, fn () => $this->demoFor($lead, $user));

        Tenancy::runAs($org->id, fn () => app(WorkflowTransitionService::class)->transitionDemoOutcome(
            $demo, DemoOutcome::InterestedOk, ['next_action' => DemoNextAction::ScheduleAnotherDemo->value, 'demo_at' => now()->addDays(8)]
        ));

        $demo->refresh();
        $this->assertSame(DemoStatus::Completed, $demo->status);
        $new = Demo::withoutGlobalScopes()->where('lead_id', $lead->id)->where('id', '!=', $demo->id)->firstOrFail();
        $this->assertNull($new->rescheduled_from_id);
        $this->assertSame('demo', $new->origin_type);
    }

    public function test_demo_origin_can_be_another_demo_via_the_stable_morph_alias(): void
    {
        $org = $this->org();
        $user = $this->userIn($org, UserRole::Employee);
        $lead = $this->leadIn($org, $user);
        $demo = Tenancy::runAs($org->id, fn () => $this->demoFor($lead, $user));

        Tenancy::runAs($org->id, fn () => app(WorkflowTransitionService::class)->transitionDemoOutcome(
            $demo, DemoOutcome::AnotherDemoRequired, ['demo_at' => now()->addDays(7)]
        ));

        $new = Demo::withoutGlobalScopes()->where('lead_id', $lead->id)->where('id', '!=', $demo->id)->firstOrFail();
        Tenancy::runAs($org->id, function () use ($new, $demo) {
            $this->assertInstanceOf(Demo::class, $new->origin);
            $this->assertSame($demo->id, $new->origin->id);
        });
    }

    public function test_stable_morph_map_includes_the_demo_alias(): void
    {
        $this->assertSame(Demo::class, \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel('demo'));
    }

    public function test_cross_tenant_origin_reference_is_rejected(): void
    {
        $orgA = $this->org();
        $orgB = $this->org();
        $userA = $this->userIn($orgA, UserRole::Employee);
        $userB = $this->userIn($orgB, UserRole::Employee);
        $leadA = $this->leadIn($orgA, $userA);
        $leadB = $this->leadIn($orgB, $userB);
        $demoB = Tenancy::runAs($orgB->id, fn () => $this->demoFor($leadB, $userB));

        $this->expectException(\RuntimeException::class);

        Tenancy::runAs($orgA->id, function () use ($leadA, $userA, $demoB) {
            $demo = new Demo([
                'prospect_id' => $leadA->prospect_id,
                'lead_id' => $leadA->id,
                'assigned_to' => $userA->id,
                'created_by' => $userA->id,
                'demo_at' => now()->addDay(),
                'mode' => DemoMode::OnSite->value,
                'location' => 'Site',
                'status' => DemoStatus::Scheduled->value,
            ]);
            $demo->forceFill(['origin_type' => 'demo', 'origin_id' => $demoB->id]);
            $demo->save();
        });
    }

    // --- Not Interested / No Progression: no downstream activity ---

    public function test_not_interested_creates_no_downstream_activity_and_preserves_history(): void
    {
        $org = $this->org();
        $user = $this->userIn($org, UserRole::Employee);
        $lead = $this->leadIn($org, $user);
        $demo = Tenancy::runAs($org->id, fn () => $this->demoFor($lead, $user));

        Tenancy::runAs($org->id, fn () => app(WorkflowTransitionService::class)->transitionDemoOutcome(
            $demo, DemoOutcome::NotInterestedNoProgression, []
        ));

        $demo->refresh();
        $this->assertSame(DemoStatus::Completed, $demo->status);
        $this->assertSame(DemoOutcome::NotInterestedNoProgression, $demo->outcome);
        $this->assertSame(DemoNextAction::NoFurtherAction, $demo->next_action);
        $this->assertSame(1, Demo::withoutGlobalScopes()->where('lead_id', $lead->id)->count());
        $this->assertSame(0, \App\Models\Proposal::withoutGlobalScopes()->where('lead_id', $lead->id)->count());
    }

    // --- next_action determinism ---

    public function test_correction_needed_requires_correction_comments_and_an_explicit_next_action(): void
    {
        $org = $this->org();
        $user = $this->userIn($org, UserRole::Employee);
        $lead = $this->leadIn($org, $user);
        $demo = Tenancy::runAs($org->id, fn () => $this->demoFor($lead, $user));

        $this->expectException(LogicException::class);

        Tenancy::runAs($org->id, fn () => app(WorkflowTransitionService::class)->transitionDemoOutcome(
            $demo, DemoOutcome::CorrectionNeeded, ['correction_comments' => 'Needs pricing correction']
            // next_action omitted -> must throw
        ));
    }

    public function test_other_requires_notes_and_next_action(): void
    {
        $org = $this->org();
        $user = $this->userIn($org, UserRole::Employee);
        $lead = $this->leadIn($org, $user);
        $demo = Tenancy::runAs($org->id, fn () => $this->demoFor($lead, $user));

        $this->expectException(LogicException::class);

        Tenancy::runAs($org->id, fn () => app(WorkflowTransitionService::class)->transitionDemoOutcome(
            $demo, DemoOutcome::Other, []
        ));
    }

    public function test_deterministic_outcomes_automatically_receive_the_correct_next_action(): void
    {
        $cases = [
            [DemoOutcome::AnotherDemoRequired, DemoNextAction::ScheduleAnotherDemo, ['demo_at' => now()->addDays(2)]],
            [DemoOutcome::MoreTimeDiscussion, DemoNextAction::CreateFollowUp, ['follow_up_at' => now()->addDays(2), 'reason' => 'Discuss further']],
            [DemoOutcome::RequirementClarificationNeeded, DemoNextAction::ReturnToLeadForClarification, []],
            [DemoOutcome::ProposalRequired, DemoNextAction::StartProposal, []],
            [DemoOutcome::NotInterestedNoProgression, DemoNextAction::NoFurtherAction, []],
        ];

        foreach ($cases as [$outcome, $expectedNextAction, $data]) {
            $org = $this->org();
            $user = $this->userIn($org, UserRole::Employee);
            $lead = $this->leadIn($org, $user);
            $demo = Tenancy::runAs($org->id, fn () => $this->demoFor($lead, $user));

            Tenancy::runAs($org->id, fn () => app(WorkflowTransitionService::class)->transitionDemoOutcome($demo, $outcome, $data));

            $this->assertSame($expectedNextAction, $demo->fresh()->next_action, "outcome {$outcome->value}");
        }
    }

    public function test_deterministic_outcomes_reject_a_contradictory_supplied_next_action(): void
    {
        $org = $this->org();
        $user = $this->userIn($org, UserRole::Employee);
        $lead = $this->leadIn($org, $user);
        $demo = Tenancy::runAs($org->id, fn () => $this->demoFor($lead, $user));

        $this->expectException(LogicException::class);

        Tenancy::runAs($org->id, fn () => app(WorkflowTransitionService::class)->transitionDemoOutcome(
            $demo, DemoOutcome::ProposalRequired, ['next_action' => DemoNextAction::ScheduleAnotherDemo->value]
        ));
    }

    public function test_proposal_required_does_not_ask_for_a_redundant_second_next_action_choice(): void
    {
        $org = $this->org();
        $user = $this->userIn($org, UserRole::Employee);
        $lead = $this->leadIn($org, $user);
        $demo = Tenancy::runAs($org->id, fn () => $this->demoFor($lead, $user));

        // No 'next_action' supplied at all -> still succeeds, deterministically resolved.
        Tenancy::runAs($org->id, fn () => app(WorkflowTransitionService::class)->transitionDemoOutcome(
            $demo, DemoOutcome::ProposalRequired, []
        ));

        $this->assertSame(DemoNextAction::StartProposal, $demo->fresh()->next_action);
        $this->assertSame(1, \App\Models\Proposal::withoutGlobalScopes()->where('lead_id', $lead->id)->count());
    }

    public function test_interested_ok_does_not_silently_create_a_proposal_without_explicit_confirmation(): void
    {
        $org = $this->org();
        $user = $this->userIn($org, UserRole::Employee);
        $lead = $this->leadIn($org, $user);
        $demo = Tenancy::runAs($org->id, fn () => $this->demoFor($lead, $user));

        $this->expectException(LogicException::class);

        Tenancy::runAs($org->id, fn () => app(WorkflowTransitionService::class)->transitionDemoOutcome(
            $demo, DemoOutcome::InterestedOk, [] // no next_action -> must throw, never auto-create a Proposal
        ));

        $this->assertSame(0, \App\Models\Proposal::withoutGlobalScopes()->where('lead_id', $lead->id)->count());
    }

    public function test_interested_ok_with_start_proposal_creates_the_proposal(): void
    {
        $org = $this->org();
        $user = $this->userIn($org, UserRole::Employee);
        $lead = $this->leadIn($org, $user);
        $demo = Tenancy::runAs($org->id, fn () => $this->demoFor($lead, $user));

        Tenancy::runAs($org->id, fn () => app(WorkflowTransitionService::class)->transitionDemoOutcome(
            $demo, DemoOutcome::InterestedOk, ['next_action' => DemoNextAction::StartProposal->value]
        ));

        $this->assertSame(1, \App\Models\Proposal::withoutGlobalScopes()->where('lead_id', $lead->id)->count());
    }

    public function test_requirement_clarification_returns_the_same_lead_to_requirement_collection_never_a_duplicate(): void
    {
        $org = $this->org();
        $user = $this->userIn($org, UserRole::Employee);
        $lead = $this->leadIn($org, $user);
        $demo = Tenancy::runAs($org->id, fn () => $this->demoFor($lead, $user));

        Tenancy::runAs($org->id, fn () => app(WorkflowTransitionService::class)->transitionDemoOutcome(
            $demo, DemoOutcome::RequirementClarificationNeeded, ['clarification_notes' => 'Need updated quantity']
        ));

        $this->assertSame(LeadStatus::RequirementCollection, $lead->fresh()->status);
        $this->assertSame(1, Lead::withoutGlobalScopes()->where('prospect_id', $lead->prospect_id)->count());
    }

    public function test_more_time_discussion_creates_a_real_follow_up_without_duplicating_the_schedule_on_demo(): void
    {
        $org = $this->org();
        $user = $this->userIn($org, UserRole::Employee);
        $lead = $this->leadIn($org, $user);
        $demo = Tenancy::runAs($org->id, fn () => $this->demoFor($lead, $user));
        $followUpAt = now()->addDays(5)->startOfMinute();

        Tenancy::runAs($org->id, function () use ($demo, $followUpAt) {
            app(WorkflowTransitionService::class)->transitionDemoOutcome(
                $demo, DemoOutcome::MoreTimeDiscussion, ['follow_up_at' => $followUpAt, 'reason' => 'Wants to discuss with partner']
            );

            $followUp = $demo->fresh()->generatedFollowUp;
            $this->assertNotNull($followUp);
            $this->assertTrue($followUpAt->equalTo($followUp->follow_up_at));
        });

        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('demos', 'follow_up_at'));
    }

    public function test_demo_transition_audit_records_both_outcome_and_next_action(): void
    {
        $org = $this->org();
        $user = $this->userIn($org, UserRole::Employee);
        $lead = $this->leadIn($org, $user);
        $demo = Tenancy::runAs($org->id, fn () => $this->demoFor($lead, $user));

        Tenancy::runAs($org->id, fn () => app(WorkflowTransitionService::class)->transitionDemoOutcome(
            $demo, DemoOutcome::ProposalRequired, []
        ));

        $event = AuditEvent::withoutGlobalScopes()
            ->where('entity_type', 'Demo')->where('entity_id', $demo->id)->where('action', 'demo_outcome_recorded')->first();

        $this->assertNotNull($event);
        $this->assertSame(DemoOutcome::ProposalRequired->value, $event->after['outcome']);
        $this->assertSame(DemoNextAction::StartProposal->value, $event->after['next_action']);
    }

    public function test_demo_transition_failure_rolls_back_outcome_next_action_status_and_downstream_record_together(): void
    {
        $org = $this->org();
        $user = $this->userIn($org, UserRole::Employee);
        $lead = $this->leadIn($org, $user);
        $demo = Tenancy::runAs($org->id, fn () => $this->demoFor($lead, $user));

        try {
            Tenancy::runAs($org->id, fn () => app(WorkflowTransitionService::class)->transitionDemoOutcome(
                $demo, DemoOutcome::CorrectionNeeded, [] // missing correction_comments -> throws before any write
            ));
            $this->fail('Expected missing correction_comments to throw.');
        } catch (LogicException $e) {
            // expected
        }

        $demo->refresh();
        $this->assertSame(DemoStatus::Scheduled, $demo->status);
        $this->assertNull($demo->outcome);
        $this->assertNull($demo->next_action);
        $this->assertSame(0, AuditEvent::withoutGlobalScopes()->where('entity_type', 'Demo')->where('action', 'demo_outcome_recorded')->count());
    }
}
