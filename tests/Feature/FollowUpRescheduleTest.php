<?php

namespace Tests\Feature;

use App\Enums\FollowUpStatus;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Services\RescheduleService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

/**
 * Phase 2: Follow-Up reschedule/history. "Old activity -> Rescheduled/
 * history, new activity -> new active Pending record" — the same
 * business record is preserved, never overwritten in place, and normal
 * Edit can never silently bypass this (App\Models\Concerns\
 * GuardsScheduleAgainstDirectEdit + FollowUpResource's read-only field).
 *
 * Every scoped-model assertion runs inside Tenancy::runAs() — this app's
 * base Tests\TestCase already establishes a DEFAULT ambient tenant context
 * in setUp() (see its own docblock), so touching a record that belongs to
 * a deliberately-created second Organization must always be done inside
 * an explicit runAs() for that Organization, mirroring
 * HierarchyValidationTest/OrganizationBackfillTest's convention.
 */
class FollowUpRescheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_reschedule_preserves_the_old_record_as_history_and_creates_a_distinct_active_replacement(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $original = \App\Models\FollowUp::create([
                'prospect_id' => $prospect->id,
                'user_id' => $user->id,
                'follow_up_at' => now()->addDay(),
                'reason' => 'Callback requested',
                'status' => FollowUpStatus::Pending,
            ]);
            $newTime = now()->addDays(3)->startOfMinute();

            $replacement = app(RescheduleService::class)->reschedule($original, ['follow_up_at' => $newTime], 'Customer asked to move it');

            $this->assertNotSame($original->id, $replacement->id);
            $this->assertSame(FollowUpStatus::Rescheduled, $original->fresh()->status);
            $this->assertSame(FollowUpStatus::Pending, $replacement->status);
            $this->assertTrue($newTime->equalTo($replacement->follow_up_at));
            $this->assertDatabaseHas('follow_ups', ['id' => $original->id, 'status' => 'rescheduled']);
        });
    }

    public function test_reschedule_linkage_is_correct_and_no_second_physical_column_is_used(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $original = \App\Models\FollowUp::create([
                'prospect_id' => $prospect->id,
                'user_id' => $user->id,
                'follow_up_at' => now()->addDay(),
                'reason' => 'Callback requested',
                'status' => FollowUpStatus::Pending,
            ]);

            $replacement = app(RescheduleService::class)->reschedule($original, ['follow_up_at' => now()->addDays(5)->startOfMinute()]);

            $this->assertSame($original->id, $replacement->rescheduledFrom->id);
            $this->assertSame($replacement->id, $original->fresh()->replacedBy->id);
        });

        $this->assertFalse(Schema::hasColumn('follow_ups', 'replaced_by_id'));
    }

    public function test_transaction_rollback_leaves_the_original_untouched_if_replacement_creation_fails(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $original = \App\Models\FollowUp::create([
                'prospect_id' => $prospect->id,
                'user_id' => $user->id,
                'follow_up_at' => now()->addDay(),
                'reason' => 'Callback requested',
                'status' => FollowUpStatus::Pending,
            ]);

            try {
                app(RescheduleService::class)->reschedule(
                    $original, ['follow_up_at' => now()->addDays(2), 'reason' => str_repeat('x', 1000)]
                );
                $this->fail('Expected the oversized reason column to fail the insert.');
            } catch (\Throwable $e) {
                // expected: reason column has a 255-char limit
            }

            $this->assertSame(FollowUpStatus::Pending, $original->fresh()->status);
            $this->assertSame(1, \App\Models\FollowUp::query()->where('prospect_id', $original->prospect_id)->count());
            $this->assertSame(0, AuditEvent::withoutGlobalScopes()->where('entity_type', 'FollowUp')->where('action', 'rescheduled')->count());
        });
    }

    public function test_editing_an_unrelated_field_does_not_trigger_a_reschedule(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $original = \App\Models\FollowUp::create([
                'prospect_id' => $prospect->id,
                'user_id' => $user->id,
                'follow_up_at' => now()->addDay(),
                'reason' => 'Callback requested',
                'status' => FollowUpStatus::Pending,
            ]);

            $original->update(['reason' => 'Updated reason only']);

            $this->assertSame(FollowUpStatus::Pending, $original->fresh()->status);
            $this->assertSame(1, \App\Models\FollowUp::query()->where('prospect_id', $original->prospect_id)->count());
        });
    }

    public function test_direct_update_cannot_change_an_already_set_follow_up_at(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $original = \App\Models\FollowUp::create([
                'prospect_id' => $prospect->id,
                'user_id' => $user->id,
                'follow_up_at' => now()->addDay(),
                'reason' => 'Callback requested',
                'status' => FollowUpStatus::Pending,
            ]);

            $this->expectException(LogicException::class);

            $original->update(['follow_up_at' => now()->addDays(9)]);
        });
    }

    public function test_filling_in_a_still_null_follow_up_at_for_the_first_time_is_not_blocked(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

            // Simulates a Callback Requested call's auto-routed Follow-Up:
            // no callback time known yet at creation (Phase 3: No Answer no
            // longer routes to a Follow-Up at all).
            $call = \App\Models\CallRecord::create([
                'prospect_id' => $prospect->id,
                'user_id' => $user->id,
                'called_at' => now(),
                'outcome' => \App\Enums\CallOutcome::CallbackRequested,
                'notes' => 'Asked to call back later.',
            ]);
            $followUp = $call->fresh()->followUp;

            $this->assertNull($followUp->follow_up_at);

            $followUp->update(['follow_up_at' => now()->addDay()]);

            $this->assertNotNull($followUp->fresh()->follow_up_at);
        });
    }

    public function test_only_a_pending_follow_up_can_be_rescheduled(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $original = \App\Models\FollowUp::create([
                'prospect_id' => $prospect->id,
                'user_id' => $user->id,
                'follow_up_at' => now()->addDay(),
                'reason' => 'Callback requested',
                'status' => FollowUpStatus::Pending,
            ]);
            $original->update(['status' => FollowUpStatus::Cancelled]);

            $this->expectException(LogicException::class);

            app(RescheduleService::class)->reschedule($original->fresh(), ['follow_up_at' => now()->addDays(2)]);
        });
    }

    public function test_audit_event_records_actor_old_schedule_new_schedule_and_reason(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $oldTime = now()->addDay()->startOfMinute();
            $original = \App\Models\FollowUp::create([
                'prospect_id' => $prospect->id,
                'user_id' => $user->id,
                'follow_up_at' => $oldTime,
                'reason' => 'Callback requested',
                'status' => FollowUpStatus::Pending,
            ]);
            $newTime = now()->addDays(4)->startOfMinute();

            $this->actingAs($user);
            $replacement = app(RescheduleService::class)->reschedule($original, ['follow_up_at' => $newTime], 'Customer requested later slot');

            $event = AuditEvent::withoutGlobalScopes()
                ->where('entity_type', 'FollowUp')
                ->where('entity_id', $original->id)
                ->where('action', 'rescheduled')
                ->first();

            $this->assertNotNull($event);
            $this->assertSame($user->id, $event->actor_user_id);
            $this->assertSame($oldTime->toIso8601String(), $event->before['follow_up_at']);
            $this->assertSame($newTime->toIso8601String(), $event->after['follow_up_at']);
            $this->assertSame($replacement->id, $event->after['replacement_id']);
            $this->assertSame('Customer requested later slot', $event->description);
        });
    }

    public function test_cross_tenant_reschedule_is_rejected(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $followUpA = Tenancy::runAs($orgA->id, function () use ($orgA) {
            $user = User::factory()->create(['organization_id' => $orgA->id]);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

            return \App\Models\FollowUp::create([
                'prospect_id' => $prospect->id,
                'user_id' => $user->id,
                'follow_up_at' => now()->addDay(),
                'reason' => 'Callback requested',
                'status' => FollowUpStatus::Pending,
            ]);
        });

        // Attempting to reschedule an Org A record while ambient context is
        // Org B must fail closed — the record simply isn't reachable
        // (organization scoping applies to the lock/re-read query itself),
        // never silently rescheduled across tenants.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Tenancy::runAs($orgB->id, fn () => app(RescheduleService::class)->reschedule(
            $followUpA, ['follow_up_at' => now()->addDays(2)]
        ));
    }
}
