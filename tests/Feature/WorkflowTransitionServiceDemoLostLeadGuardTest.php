<?php

namespace Tests\Feature;

use App\Enums\DemoMode;
use App\Enums\DemoStatus;
use App\Enums\LeadStatus;
use App\Models\Demo;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Services\WorkflowTransitionService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Lost-Lead Demo protection fix: WorkflowTransitionService::
 * transitionToDemo() is the single shared entry point for every valid
 * route into Demo (Follow-Up's "Schedule Demo" resource action, Pipeline
 * Board's Lead->Demo cross-drop, and any future caller) — a Lost Lead is a
 * closed outcome (Lead::markLost()'s own docblock: an outcome applied on
 * top of wherever the Lead currently is, never un-done) and must be
 * rejected here, authoritatively, regardless of which caller reaches it or
 * whether that caller's own UI already filters Lost Leads out.
 */
class WorkflowTransitionServiceDemoLostLeadGuardTest extends TestCase
{
    use RefreshDatabase;

    private function newFollowUp(User $user, int $prospectId): FollowUp
    {
        return FollowUp::create([
            'prospect_id' => $prospectId,
            'user_id' => $user->id,
            'follow_up_at' => now()->addDay(),
            'reason' => 'Callback requested',
            'status' => 'pending',
        ]);
    }

    public function test_transitioning_to_demo_rejects_a_lost_lead_when_called_directly(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $lead = Lead::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'stage' => 'requirement_collection', 'status' => LeadStatus::RequirementCollection, 'temperature' => 'warm',
            ]);
            $lead->markLost('Went with a competitor.');
            $followUp = $this->newFollowUp($user, $prospect->id);

            $threw = false;

            try {
                app(WorkflowTransitionService::class)->transitionToDemo($lead, $followUp, 'follow_up', [
                    'demo_at' => now()->addDays(2),
                    'mode' => DemoMode::Online->value,
                    'meeting_link' => 'https://meet.example.com/demo',
                ]);
            } catch (LogicException $e) {
                $threw = true;
                $this->assertStringContainsString('Lost', $e->getMessage());
            }

            $this->assertTrue($threw, 'transitionToDemo() must reject a Lost Lead.');
            $this->assertSame(0, Demo::query()->count(), 'No Demo row may be persisted when the target Lead is Lost.');
        });
    }

    public function test_transitioning_to_demo_still_succeeds_for_an_active_non_lost_lead(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $lead = Lead::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'stage' => 'requirement_collection', 'status' => LeadStatus::RequirementCollection, 'temperature' => 'warm',
            ]);
            $followUp = $this->newFollowUp($user, $prospect->id);

            $demo = app(WorkflowTransitionService::class)->transitionToDemo($lead, $followUp, 'follow_up', [
                'demo_at' => now()->addDays(2),
                'mode' => DemoMode::Online->value,
                'meeting_link' => 'https://meet.example.com/demo',
            ]);

            $this->assertSame($lead->id, $demo->lead_id);
            $this->assertSame(DemoStatus::Scheduled, $demo->status);
            $this->assertSame('follow_up', $demo->origin_type);
            $this->assertSame($followUp->id, $demo->origin_id);
        });
    }

    /**
     * A concurrent request marking the Lead Lost between when the caller
     * loaded it and when this transaction actually runs must still be
     * caught — the guard reads `$lead->is_lost` from the instance passed
     * in, so a caller that re-fetches inside its own transaction (as every
     * current caller of this method does not need to, since none locks the
     * Lead row here) still can't bypass it by passing a stale, no-longer-
     * accurate in-memory Lead. This test simply confirms the guard uses
     * the Lead's actual current persisted state, not a cached flag.
     */
    public function test_a_lead_marked_lost_after_being_loaded_is_still_rejected_once_refreshed(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $lead = Lead::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'stage' => 'requirement_collection', 'status' => LeadStatus::RequirementCollection, 'temperature' => 'warm',
            ]);
            $followUp = $this->newFollowUp($user, $prospect->id);

            // Mark it Lost via a fresh instance (simulating another
            // request), then refresh the original in-memory reference
            // before passing it to the service — exactly what a real
            // caller re-fetching the record would see.
            Lead::find($lead->id)->markLost('Went cold.');
            $lead->refresh();

            $threw = false;

            try {
                app(WorkflowTransitionService::class)->transitionToDemo($lead, $followUp, 'follow_up', [
                    'demo_at' => now()->addDays(2),
                    'mode' => DemoMode::Online->value,
                    'meeting_link' => 'https://meet.example.com/demo',
                ]);
            } catch (LogicException $e) {
                $threw = true;
            }

            $this->assertTrue($threw);
            $this->assertSame(0, Demo::query()->count());
        });
    }
}
