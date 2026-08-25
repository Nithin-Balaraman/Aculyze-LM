<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Models\CallRecord;
use App\Models\Prospect;
use App\Services\CallRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the routing rules in AGENTS.md section 15 and the duplicate
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
            $call = $this->makeCall($prospect, $outcome, $attributes);
            $this->assertDatabaseHas('call_records', ['id' => $call->id, 'outcome' => $outcome->value]);
        }
    }

    public function test_no_answer_routes_to_follow_up_only(): void
    {
        $prospect = Prospect::factory()->create();
        $call = $this->makeCall($prospect, CallOutcome::NoAnswer);

        $this->assertNotNull($call->fresh()->followUp);
        $this->assertNull($call->fresh()->appointment);
        $this->assertNull($call->fresh()->lead);
    }

    public function test_switched_off_routes_to_follow_up_only(): void
    {
        $call = $this->makeCall(Prospect::factory()->create(), CallOutcome::SwitchedOff);

        $this->assertNotNull($call->fresh()->followUp);
        $this->assertNull($call->fresh()->appointment);
    }

    public function test_not_reachable_routes_to_follow_up_only(): void
    {
        $call = $this->makeCall(Prospect::factory()->create(), CallOutcome::NotReachable);

        $this->assertNotNull($call->fresh()->followUp);
        $this->assertNull($call->fresh()->appointment);
    }

    public function test_callback_requested_routes_to_follow_up_only(): void
    {
        $call = $this->makeCall(Prospect::factory()->create(), CallOutcome::CallbackRequested, ['notes' => 'Asked to call back next week.']);

        $this->assertNotNull($call->fresh()->followUp);
        $this->assertNull($call->fresh()->appointment);
    }

    public function test_concerned_person_not_available_routes_to_follow_up_only(): void
    {
        $call = $this->makeCall(Prospect::factory()->create(), CallOutcome::ConcernedPersonNotAvailable, ['notes' => 'Decision-maker was out of office.']);

        $this->assertNotNull($call->fresh()->followUp);
        $this->assertNull($call->fresh()->appointment);
        $this->assertNull($call->fresh()->lead);
    }

    public function test_profile_requested_routes_to_follow_up_only(): void
    {
        $call = $this->makeCall(Prospect::factory()->create(), CallOutcome::ProfileRequested, ['notes' => 'Asked for a company profile before deciding.']);

        $this->assertNotNull($call->fresh()->followUp);
        $this->assertNull($call->fresh()->appointment);
        $this->assertNull($call->fresh()->lead);
    }

    public function test_appointment_set_routes_to_appointment_only(): void
    {
        $call = $this->makeCall(Prospect::factory()->create(), CallOutcome::AppointmentSet, ['notes' => 'Agreed to a site visit.']);

        $this->assertNotNull($call->fresh()->appointment);
        $this->assertNull($call->fresh()->followUp);
        $this->assertNull($call->fresh()->lead);
    }

    public function test_future_opportunity_routes_nowhere(): void
    {
        $call = $this->makeCall(Prospect::factory()->create(), CallOutcome::FutureOpportunity, ['notes' => 'No budget this year, revisit later.']);

        $this->assertNull($call->fresh()->appointment);
        $this->assertNull($call->fresh()->followUp);
        $this->assertNull($call->fresh()->lead);
        $this->assertNotNull($call->fresh()->processed_at);
    }

    public function test_others_routes_nowhere(): void
    {
        $call = $this->makeCall(Prospect::factory()->create(), CallOutcome::Others, ['notes' => 'Prospect no longer exists as a company.']);

        $this->assertNull($call->fresh()->appointment);
        $this->assertNull($call->fresh()->followUp);
        $this->assertNull($call->fresh()->lead);
        $this->assertNotNull($call->fresh()->processed_at);
    }

    public function test_requirement_identified_routes_to_appointment_and_lead(): void
    {
        $call = $this->makeCall(Prospect::factory()->create(), CallOutcome::RequirementIdentified, ['notes' => 'Interested in a full rollout.']);

        $this->assertNotNull($call->fresh()->appointment);
        $this->assertNotNull($call->fresh()->lead);
        $this->assertNull($call->fresh()->followUp);
    }

    public function test_routing_is_idempotent_and_never_creates_duplicates(): void
    {
        $prospect = Prospect::factory()->create();
        $call = $this->makeCall($prospect, CallOutcome::RequirementIdentified, ['notes' => 'Interested in a full rollout.']);

        $this->assertSame(1, \App\Models\Appointment::count());
        $this->assertSame(1, \App\Models\Lead::count());

        // Simulate a retry / re-processing attempt (e.g. a queued job firing
        // twice, or an admin re-saving the record).
        app(CallRoutingService::class)->route($call->fresh());
        app(CallRoutingService::class)->route($call->fresh());

        $this->assertSame(1, \App\Models\Appointment::count());
        $this->assertSame(1, \App\Models\Lead::count());
    }

    public function test_editing_an_existing_call_record_does_not_trigger_routing_again(): void
    {
        $call = $this->makeCall(Prospect::factory()->create(), CallOutcome::NoAnswer);
        $this->assertSame(1, \App\Models\FollowUp::count());

        // Editing the call (e.g. correcting a typo in notes) must not create
        // a second Follow-Up.
        $call->update(['notes' => 'Corrected note.']);

        $this->assertSame(1, \App\Models\FollowUp::count());
    }
}
