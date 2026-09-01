<?php

namespace Tests\Feature;

use App\Enums\CallNextAction;
use App\Enums\CallOutcome;
use App\Enums\ProfileSentStatus;
use App\Models\Appointment;
use App\Models\CallRecord;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Prospect;
use App\Services\CallRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the Phase 3 finalized Call routing rules (see AGENTS.md sections
 * 15/46 and the Phase 3 Call outcome corrections) and the duplicate
 * protection required by section 16.
 */
class CallRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function makeCall(Prospect $prospect, CallOutcome $outcome, array $attributes = []): CallRecord
    {
        return CallRecord::create(array_merge([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'called_at' => now(),
            'outcome' => $outcome,
        ], $attributes));
    }

    public function test_every_outcome_creates_a_call_record(): void
    {
        $prospect = Prospect::factory()->create();

        foreach (CallOutcome::cases() as $outcome) {
            // Every outcome except No Answer/Switched Off/Not Reachable
            // requires Notes to save at all — see CallRecord::booted().
            $attributes = $outcome->requiresNotes() ? ['notes' => 'A real conversation happened, see notes.'] : [];

            if ($outcome === CallOutcome::Others) {
                $attributes['next_action'] = CallNextAction::NoFurtherAction;
            }

            if ($outcome === CallOutcome::ProfileRequested) {
                $attributes['profile_sent_status'] = ProfileSentStatus::Pending;
            }

            $call = $this->makeCall($prospect, $outcome, $attributes);
            $this->assertDatabaseHas('call_records', ['id' => $call->id, 'outcome' => $outcome->value]);
        }
    }

    public function test_no_answer_creates_no_downstream_activity(): void
    {
        $call = $this->makeCall(Prospect::factory()->create(), CallOutcome::NoAnswer);

        $this->assertNull($call->fresh()->followUp);
        $this->assertNull($call->fresh()->appointment);
        $this->assertNull($call->fresh()->lead);
    }

    public function test_switched_off_creates_no_downstream_activity(): void
    {
        $call = $this->makeCall(Prospect::factory()->create(), CallOutcome::SwitchedOff);

        $this->assertNull($call->fresh()->followUp);
        $this->assertNull($call->fresh()->appointment);
        $this->assertNull($call->fresh()->lead);
    }

    public function test_not_reachable_creates_no_downstream_activity(): void
    {
        $call = $this->makeCall(Prospect::factory()->create(), CallOutcome::NotReachable);

        $this->assertNull($call->fresh()->followUp);
        $this->assertNull($call->fresh()->appointment);
        $this->assertNull($call->fresh()->lead);
    }

    public function test_callback_requested_routes_to_follow_up_only(): void
    {
        $call = $this->makeCall(Prospect::factory()->create(), CallOutcome::CallbackRequested, [
            'notes' => 'Asked to call back next week.',
            'follow_up_at' => now()->addDays(3),
        ]);

        $this->assertNotNull($call->fresh()->followUp);
        $this->assertNull($call->fresh()->appointment);
    }

    public function test_concerned_person_not_available_without_explicit_callback_creates_no_follow_up(): void
    {
        $call = $this->makeCall(Prospect::factory()->create(), CallOutcome::ConcernedPersonNotAvailable, [
            'notes' => 'Decision-maker was out of office.',
        ]);

        $this->assertNull($call->fresh()->followUp);
        $this->assertNull($call->fresh()->appointment);
        $this->assertNull($call->fresh()->lead);
    }

    public function test_concerned_person_not_available_with_explicit_callback_creates_exactly_one_follow_up(): void
    {
        $call = $this->makeCall(Prospect::factory()->create(), CallOutcome::ConcernedPersonNotAvailable, [
            'notes' => 'Agreed to call back once the decision-maker returns.',
            'follow_up_at' => now()->addDays(2),
        ]);

        $this->assertNotNull($call->fresh()->followUp);
        $this->assertSame(1, FollowUp::count());
        $this->assertNull($call->fresh()->appointment);
        $this->assertNull($call->fresh()->lead);
    }

    public function test_profile_requested_creates_no_follow_up_by_default(): void
    {
        $call = $this->makeCall(Prospect::factory()->create(), CallOutcome::ProfileRequested, [
            'notes' => 'Asked for a company profile before deciding.',
            'profile_sent_status' => ProfileSentStatus::Pending,
        ]);

        $this->assertNull($call->fresh()->followUp);
        $this->assertNull($call->fresh()->appointment);
        $this->assertNull($call->fresh()->lead);
    }

    public function test_profile_requested_with_explicit_follow_up_creates_exactly_one_follow_up(): void
    {
        $call = $this->makeCall(Prospect::factory()->create(), CallOutcome::ProfileRequested, [
            'notes' => 'Asked for a company profile; also wants a callback next week.',
            'profile_sent_status' => ProfileSentStatus::Pending,
            'follow_up_at' => now()->addDays(5),
        ]);

        $this->assertNotNull($call->fresh()->followUp);
        $this->assertSame(1, FollowUp::count());
    }

    public function test_appointment_set_routes_to_appointment_only(): void
    {
        $call = $this->makeCall(Prospect::factory()->create(), CallOutcome::AppointmentSet, ['notes' => 'Agreed to a site visit.']);

        $this->assertNotNull($call->fresh()->appointment);
        $this->assertNull($call->fresh()->followUp);
        $this->assertNull($call->fresh()->lead);
    }

    public function test_no_current_requirement_creates_no_automatic_downstream_activity(): void
    {
        $call = $this->makeCall(Prospect::factory()->create(), CallOutcome::NoCurrentRequirement, ['notes' => 'No budget this year, revisit later.']);

        $this->assertNull($call->fresh()->appointment);
        $this->assertNull($call->fresh()->followUp);
        $this->assertNull($call->fresh()->lead);
        $this->assertNotNull($call->fresh()->processed_at);
    }

    public function test_other_without_next_action_fails_to_save(): void
    {
        $this->expectException(\LogicException::class);

        $this->makeCall(Prospect::factory()->create(), CallOutcome::Others, ['notes' => 'Prospect no longer exists as a company.']);
    }

    public function test_other_with_no_further_action_creates_nothing(): void
    {
        $call = $this->makeCall(Prospect::factory()->create(), CallOutcome::Others, [
            'notes' => 'Prospect no longer exists as a company.',
            'next_action' => CallNextAction::NoFurtherAction,
        ]);

        $this->assertNull($call->fresh()->appointment);
        $this->assertNull($call->fresh()->followUp);
        $this->assertNull($call->fresh()->lead);
        $this->assertNotNull($call->fresh()->processed_at);
    }

    public function test_other_with_create_follow_up_creates_exactly_one_follow_up(): void
    {
        $call = $this->makeCall(Prospect::factory()->create(), CallOutcome::Others, [
            'notes' => 'Unusual situation, needs a callback.',
            'next_action' => CallNextAction::CreateFollowUp,
            'follow_up_at' => now()->addDays(2),
        ]);

        $this->assertNotNull($call->fresh()->followUp);
        $this->assertSame(1, FollowUp::count());
    }

    public function test_other_with_create_appointment_creates_exactly_one_appointment(): void
    {
        $call = $this->makeCall(Prospect::factory()->create(), CallOutcome::Others, [
            'notes' => 'Unusual situation, needs a site visit.',
            'next_action' => CallNextAction::CreateAppointment,
            'appointment_at' => now()->addDays(3),
        ]);

        $this->assertNotNull($call->fresh()->appointment);
        $this->assertSame(1, Appointment::count());
    }

    public function test_other_with_create_lead_creates_exactly_one_lead(): void
    {
        $call = $this->makeCall(Prospect::factory()->create(), CallOutcome::Others, [
            'notes' => 'Unusual situation, but a real requirement emerged.',
            'next_action' => CallNextAction::CreateLead,
        ]);

        $this->assertNotNull($call->fresh()->lead);
        $this->assertSame(1, Lead::count());
    }

    public function test_requirement_identified_creates_lead_only_and_does_not_create_an_appointment(): void
    {
        $call = $this->makeCall(Prospect::factory()->create(), CallOutcome::RequirementIdentified, ['notes' => 'Interested in a full rollout.']);

        $this->assertNotNull($call->fresh()->lead);
        $this->assertSame(1, Lead::count());
        $this->assertNull($call->fresh()->appointment);
        $this->assertSame(0, Appointment::count());
        $this->assertNull($call->fresh()->followUp);
    }

    public function test_routing_is_idempotent_and_never_creates_duplicates(): void
    {
        $prospect = Prospect::factory()->create();
        $call = $this->makeCall($prospect, CallOutcome::RequirementIdentified, ['notes' => 'Interested in a full rollout.']);

        $this->assertSame(1, Lead::count());
        $this->assertSame(0, Appointment::count());

        // Simulate a retry / re-processing attempt (e.g. a queued job firing
        // twice, or an admin re-saving the record).
        app(CallRoutingService::class)->route($call->fresh());
        app(CallRoutingService::class)->route($call->fresh());

        $this->assertSame(1, Lead::count());
        $this->assertSame(0, Appointment::count());
    }

    public function test_editing_an_existing_call_record_does_not_trigger_routing_again(): void
    {
        $call = $this->makeCall(Prospect::factory()->create(), CallOutcome::CallbackRequested, [
            'notes' => 'Asked to call back next week.',
            'follow_up_at' => now()->addDays(3),
        ]);
        $this->assertSame(1, FollowUp::count());

        // Editing the call (e.g. correcting a typo in notes) must not create
        // a second Follow-Up.
        $call->update(['notes' => 'Corrected note.']);

        $this->assertSame(1, FollowUp::count());
    }
}
