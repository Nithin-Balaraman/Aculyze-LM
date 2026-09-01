<?php

namespace Tests\Feature;

use App\Enums\CallNextAction;
use App\Enums\CallOutcome;
use App\Filament\Resources\CallRecordResource\Pages\ListCallRecords;
use App\Models\Appointment;
use App\Models\CallRecord;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Prospect;
use App\Models\User;
use App\Services\CallRoutingService;
use App\Support\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Phase 3: the explicit, intentional Correct Outcome action — deliberately
 * separate from generic Edit, which must never reconcile routing. No
 * once-ever limit: a Call with no downstream history may be corrected
 * repeatedly; the moment a correction creates downstream work, that record
 * becomes the permanent blocker against any further ordinary correction.
 */
class CorrectOutcomeTest extends TestCase
{
    use RefreshDatabase;

    private function makeCall(User $owner, CallOutcome $outcome = CallOutcome::NoAnswer, array $attributes = []): CallRecord
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);

        return CallRecord::create(array_merge([
            'prospect_id' => $prospect->id,
            'user_id' => $owner->id,
            'called_at' => now(),
            'outcome' => $outcome,
        ], $attributes));
    }

    public function test_correcting_no_answer_to_callback_requested_creates_exactly_one_follow_up(): void
    {
        $employee = User::factory()->create();
        $call = $this->makeCall($employee);

        app(CallRoutingService::class)->correctOutcome(
            $call,
            CallOutcome::CallbackRequested,
            'Actually reached them, they asked for a callback.',
            ['follow_up_at' => now()->addDays(2), 'notes' => 'Asked to call back next week.']
        );

        $call->refresh();
        $this->assertSame(CallOutcome::CallbackRequested, $call->outcome);
        $this->assertSame('Actually reached them, they asked for a callback.', $call->correction_reason);
        $this->assertNotNull($call->outcome_corrected_at);
        $this->assertSame(1, FollowUp::count());
    }

    public function test_resubmitting_the_identical_correction_is_rejected_as_a_no_op_no_duplicate(): void
    {
        $employee = User::factory()->create();
        $call = $this->makeCall($employee);

        app(CallRoutingService::class)->correctOutcome(
            $call, CallOutcome::CallbackRequested, 'Reached them.', ['follow_up_at' => now()->addDays(2), 'notes' => 'x']
        );
        $this->assertSame(1, FollowUp::count());

        $this->expectException(LogicException::class);

        app(CallRoutingService::class)->correctOutcome(
            $call->fresh(), CallOutcome::CallbackRequested, 'Reached them again.', ['follow_up_at' => now()->addDays(3), 'notes' => 'x']
        );
    }

    public function test_correcting_no_answer_to_requirement_identified_creates_exactly_one_lead_and_zero_appointments(): void
    {
        $employee = User::factory()->create();
        $call = $this->makeCall($employee);

        app(CallRoutingService::class)->correctOutcome(
            $call, CallOutcome::RequirementIdentified, 'Actually a real requirement.', ['notes' => 'Interested in full rollout.']
        );

        $this->assertSame(1, Lead::count());
        $this->assertSame(0, Appointment::count());
    }

    public function test_correcting_to_no_current_requirement_creates_nothing_downstream(): void
    {
        $employee = User::factory()->create();
        $call = $this->makeCall($employee, CallOutcome::SwitchedOff);

        app(CallRoutingService::class)->correctOutcome(
            $call, CallOutcome::FutureOpportunity, 'No requirement after all.', ['notes' => 'No budget this year.']
        );

        $this->assertSame(0, FollowUp::count());
        $this->assertSame(0, Lead::count());
        $this->assertSame(0, Appointment::count());
    }

    public function test_a_second_different_correction_is_allowed_while_no_downstream_history_exists_and_both_are_audited(): void
    {
        $employee = User::factory()->create();
        $call = $this->makeCall($employee, CallOutcome::NoAnswer);

        app(CallRoutingService::class)->correctOutcome($call, CallOutcome::SwitchedOff, 'Actually switched off.');
        app(CallRoutingService::class)->correctOutcome($call->fresh(), CallOutcome::FutureOpportunity, 'No budget after all.', ['notes' => 'No budget this year.']);

        $this->assertSame(CallOutcome::FutureOpportunity, $call->fresh()->outcome);

        $events = AuditLogger::class;
        $this->assertSame(
            2,
            \App\Models\AuditEvent::withoutGlobalScopes()
                ->where('entity_type', 'CallRecord')
                ->where('entity_id', $call->id)
                ->where('action', 'call_outcome_corrected')
                ->count()
        );
    }

    public function test_a_correction_that_creates_downstream_work_blocks_any_later_different_correction(): void
    {
        $employee = User::factory()->create();
        $call = $this->makeCall($employee, CallOutcome::NoAnswer);

        app(CallRoutingService::class)->correctOutcome(
            $call, CallOutcome::CallbackRequested, 'Reached them.', ['follow_up_at' => now()->addDay(), 'notes' => 'x']
        );

        $this->expectException(LogicException::class);

        app(CallRoutingService::class)->correctOutcome(
            $call->fresh(), CallOutcome::FutureOpportunity, 'Changed my mind.'
        );
    }

    public function test_correction_conflicting_with_existing_downstream_history_is_rejected_not_silently_destroying_it(): void
    {
        $employee = User::factory()->create();
        $call = $this->makeCall($employee, CallOutcome::AppointmentSet, ['notes' => 'Agreed to a site visit.']);
        $this->assertSame(1, Appointment::count());
        $appointmentId = Appointment::sole()->id;

        try {
            app(CallRoutingService::class)->correctOutcome($call->fresh(), CallOutcome::FutureOpportunity, 'Changed my mind.');
            $this->fail('Expected the correction to be rejected.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('downstream business history', $e->getMessage());
        }

        // The Appointment is untouched — not deleted, not rewritten.
        $this->assertDatabaseHas('appointments', ['id' => $appointmentId]);
        $this->assertSame(CallOutcome::AppointmentSet, $call->fresh()->outcome);
    }

    public function test_explicit_outcome_correction_without_a_reason_is_rejected(): void
    {
        $employee = User::factory()->create();
        $call = $this->makeCall($employee);

        $this->expectException(LogicException::class);

        app(CallRoutingService::class)->correctOutcome($call, CallOutcome::SwitchedOff, '');
    }

    public function test_concurrent_double_submission_of_the_same_correction_produces_no_duplicate(): void
    {
        $employee = User::factory()->create();
        $call = $this->makeCall($employee);

        DB::transaction(function () use ($call) {
            app(CallRoutingService::class)->correctOutcome(
                $call, CallOutcome::CallbackRequested, 'Reached them.', ['follow_up_at' => now()->addDay(), 'notes' => 'x']
            );
        });

        $this->assertSame(1, FollowUp::count());

        try {
            app(CallRoutingService::class)->correctOutcome(
                $call->fresh(), CallOutcome::CallbackRequested, 'Reached them again.', ['follow_up_at' => now()->addDays(2), 'notes' => 'x']
            );
            $this->fail('Expected the resubmission to be rejected as a no-op.');
        } catch (LogicException $e) {
            // expected — same outcome, no-op
        }

        $this->assertSame(1, FollowUp::count());
    }

    public function test_correct_outcome_action_is_wired_through_the_ui_and_enforces_authorization(): void
    {
        $employee = User::factory()->create();
        $otherEmployee = User::factory()->create();
        $call = $this->makeCall($employee);

        $this->actingAs($otherEmployee);

        Livewire::test(ListCallRecords::class)
            ->assertTableActionHidden('correctOutcome', $call);

        $this->actingAs($employee);

        Livewire::test(ListCallRecords::class)
            ->callTableAction('correctOutcome', $call, data: [
                'outcome' => CallOutcome::CallbackRequested->value,
                'correction_reason' => 'Reached them after all.',
                'follow_up_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'notes' => 'Call back next week.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(CallOutcome::CallbackRequested, $call->fresh()->outcome);
        $this->assertSame(1, FollowUp::count());
    }

    public function test_correct_outcome_action_requires_a_correction_reason_through_the_ui(): void
    {
        $employee = User::factory()->create();
        $call = $this->makeCall($employee);
        $this->actingAs($employee);

        Livewire::test(ListCallRecords::class)
            ->callTableAction('correctOutcome', $call, data: [
                'outcome' => CallOutcome::SwitchedOff->value,
                'correction_reason' => '',
            ])
            ->assertHasTableActionErrors(['correction_reason' => 'required']);
    }

    public function test_correcting_other_requires_a_next_action(): void
    {
        $employee = User::factory()->create();
        $call = $this->makeCall($employee, CallOutcome::SwitchedOff);

        $this->expectException(LogicException::class);

        app(CallRoutingService::class)->correctOutcome($call, CallOutcome::Others, 'It was actually something unusual.', ['notes' => 'x']);
    }

    public function test_correcting_other_with_next_action_no_further_action_succeeds_with_no_downstream(): void
    {
        $employee = User::factory()->create();
        $call = $this->makeCall($employee, CallOutcome::SwitchedOff);

        app(CallRoutingService::class)->correctOutcome(
            $call, CallOutcome::Others, 'Unusual situation.', ['notes' => 'Not a real business anymore.', 'next_action' => CallNextAction::NoFurtherAction]
        );

        $this->assertSame(CallOutcome::Others, $call->fresh()->outcome);
        $this->assertSame(0, FollowUp::count());
        $this->assertSame(0, Lead::count());
        $this->assertSame(0, Appointment::count());
    }
}
