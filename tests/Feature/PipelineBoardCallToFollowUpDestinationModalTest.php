<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Enums\ContactMode;
use App\Enums\FollowUpStatus;
use App\Filament\Pages\PipelineBoard;
use App\Models\CallRecord;
use App\Models\FollowUp;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pipeline Board V2 (Calls column, locked design section 3): the
 * destination-specific "Schedule Follow-Up" dialog only offers Callback
 * Requested / Concerned Person Not Available / Profile Requested — and
 * Profile Requested's Follow-Up remains conditional on an explicit
 * follow_up_at, exactly matching CallOutcome::routesToConditionalFollowUp()
 * + CallRoutingService's own `filled($locked->follow_up_at)` gate. Nothing
 * here bypasses the existing routing pipeline.
 */
class PipelineBoardCallToFollowUpDestinationModalTest extends TestCase
{
    use RefreshDatabase;

    private function invokePerformCrossDrop(PipelineBoard $board, array $arguments, array $data = []): void
    {
        $method = new \ReflectionMethod($board, 'performCrossDrop');
        $method->setAccessible(true);
        $method->invoke($board, $arguments, $data);
    }

    private function makeCall(User $user, Prospect $prospect): CallRecord
    {
        return CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $user->id,
            'called_at' => now()->subDay(),
            'outcome' => CallOutcome::NoAnswer,
        ]);
    }

    /**
     * Small usability fix: Contact Person/Designation/Phone open pre-filled
     * from the exact Call being dragged, sparing the rep from retyping data
     * the system already has — the same pattern already used for "Record
     * New Call"'s Company field. Still fully editable (plain ->default(),
     * never ->disabled()).
     */
    public function test_the_live_modal_prefills_contact_fields_from_the_dragged_call(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $call = CallRecord::create([
                'prospect_id' => $prospect->id,
                'user_id' => $user->id,
                'called_at' => now()->subDay(),
                'outcome' => CallOutcome::NoAnswer,
                'contact_person_spoken_to' => 'Priya Nair',
                'designation' => 'Procurement Manager',
                'phone_called' => '9876543210',
            ]);

            Livewire::test(PipelineBoard::class)
                ->mountAction('crossDrop', [
                    'sourceResource' => 'call',
                    'sourceId' => $call->id,
                    'destResource' => 'follow_up',
                    'destStage' => 'pending',
                ])
                ->assertActionDataSet([
                    'contact_person_spoken_to' => 'Priya Nair',
                    'designation' => 'Procurement Manager',
                    'phone_called' => '9876543210',
                ]);
        });
    }

    public function test_callback_requested_creates_a_follow_up_with_optional_contact_mode(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $call = $this->makeCall($user, $prospect);

            $this->invokePerformCrossDrop(
                app(PipelineBoard::class),
                ['sourceResource' => 'call', 'sourceId' => $call->id, 'destResource' => 'follow_up', 'destStage' => 'pending'],
                [
                    'outcome' => CallOutcome::CallbackRequested->value,
                    'called_at' => now(),
                    'follow_up_at' => now()->addDays(2),
                    'follow_up_contact_mode' => ContactMode::Mail->value,
                    'notes' => 'Asked to call back next week.',
                    'contact_person_spoken_to' => 'Test Contact',
                    'designation' => 'Manager',
                    'phone_called' => '9999999999',
                ],
            );

            $followUp = FollowUp::query()->where('prospect_id', $prospect->id)->sole();
            $this->assertSame(FollowUpStatus::Pending, $followUp->status);
            $this->assertSame(ContactMode::Mail, $followUp->contact_mode);
        });
    }

    public function test_concerned_person_not_available_requires_follow_up_at_in_this_modal(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $call = $this->makeCall($user, $prospect);

            Livewire::test(PipelineBoard::class)
                ->mountAction('crossDrop', [
                    'sourceResource' => 'call',
                    'sourceId' => $call->id,
                    'destResource' => 'follow_up',
                    'destStage' => 'pending',
                ])
                ->setActionData([
                    'outcome' => CallOutcome::ConcernedPersonNotAvailable->value,
                    'notes' => 'Tried again, still unavailable.',
                ])
                ->callMountedAction()
                ->assertHasActionErrors(['follow_up_at']);

            $this->assertSame(0, FollowUp::query()->count());
        });
    }

    public function test_profile_requested_with_follow_up_at_creates_a_follow_up(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $call = $this->makeCall($user, $prospect);

            $this->invokePerformCrossDrop(
                app(PipelineBoard::class),
                ['sourceResource' => 'call', 'sourceId' => $call->id, 'destResource' => 'follow_up', 'destStage' => 'pending'],
                [
                    'outcome' => CallOutcome::ProfileRequested->value,
                    'called_at' => now(),
                    'profile_sent_status' => \App\Enums\ProfileSentStatus::Pending->value,
                    'follow_up_at' => now()->addDays(5),
                    'notes' => 'Sent the profile, will follow up.',
                    'contact_person_spoken_to' => 'Test Contact',
                    'designation' => 'Manager',
                    'phone_called' => '9999999999',
                ],
            );

            $this->assertSame(1, FollowUp::query()->where('prospect_id', $prospect->id)->count());
        });
    }

    public function test_profile_requested_without_follow_up_at_creates_no_follow_up_and_stays_in_calls(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $call = $this->makeCall($user, $prospect);

            $this->invokePerformCrossDrop(
                app(PipelineBoard::class),
                ['sourceResource' => 'call', 'sourceId' => $call->id, 'destResource' => 'follow_up', 'destStage' => 'pending'],
                [
                    'outcome' => CallOutcome::ProfileRequested->value,
                    'called_at' => now(),
                    'profile_sent_status' => \App\Enums\ProfileSentStatus::Pending->value,
                    'notes' => 'Sent nothing yet, no follow-up scheduled.',
                    'contact_person_spoken_to' => 'Test Contact',
                    'designation' => 'Manager',
                    'phone_called' => '9999999999',
                ],
            );

            $this->assertSame(0, FollowUp::query()->count());
            $newCall = CallRecord::query()->where('id', '!=', $call->id)->sole();
            $this->assertSame(CallOutcome::ProfileRequested, $newCall->outcome);
        });
    }

    /**
     * Locked design section 3C: Contact Mode is deliberately not shown for
     * Profile Requested in the live modal.
     */
    public function test_the_live_modal_hides_contact_mode_for_profile_requested(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $call = $this->makeCall($user, $prospect);

            $html = Livewire::test(PipelineBoard::class)
                ->mountAction('crossDrop', [
                    'sourceResource' => 'call',
                    'sourceId' => $call->id,
                    'destResource' => 'follow_up',
                    'destStage' => 'pending',
                ])
                ->setActionData(['outcome' => CallOutcome::ProfileRequested->value])
                ->html();

            $this->assertStringNotContainsString('Contact Mode', $html);
        });
    }
}
