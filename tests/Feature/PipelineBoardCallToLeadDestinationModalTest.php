<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Filament\Pages\PipelineBoard;
use App\Models\CallRecord;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pipeline Board V2 (Calls column, locked design section 5/D1): the
 * destination-specific "Create Lead" dialog forces outcome to
 * RequirementIdentified (no picker), requires the new Opportunity Title,
 * and preserves the existing CallRecord.notes -> Lead.requirement_details
 * mapping under the "Requirement Details" label — all through the existing
 * CallRecordObserver -> CallRoutingService -> createLead() path, never a
 * parallel Lead::create().
 */
class PipelineBoardCallToLeadDestinationModalTest extends TestCase
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
     * from the exact Call being dragged. Still fully editable.
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
                'contact_person_spoken_to' => 'Ravi Kumar',
                'designation' => 'IT Head',
                'phone_called' => '9123456780',
            ]);

            Livewire::test(PipelineBoard::class)
                ->mountAction('crossDrop', [
                    'sourceResource' => 'call',
                    'sourceId' => $call->id,
                    'destResource' => 'lead',
                    'destStage' => 'requirement_collection',
                ])
                ->assertActionDataSet([
                    'contact_person_spoken_to' => 'Ravi Kumar',
                    'designation' => 'IT Head',
                    'phone_called' => '9123456780',
                ]);
        });
    }

    public function test_the_resulting_lead_stores_opportunity_title_and_requirement_details(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $call = $this->makeCall($user, $prospect);

            $this->invokePerformCrossDrop(
                app(PipelineBoard::class),
                ['sourceResource' => 'call', 'sourceId' => $call->id, 'destResource' => 'lead', 'destStage' => 'requirement_collection'],
                [
                    'called_at' => now(),
                    'lead_opportunity_title' => 'ERP Requirement',
                    'contact_person_spoken_to' => 'Ravi Kumar',
                    'notes' => 'Wants a full ERP rollout across two branches.',
                ],
            );

            $lead = Lead::query()->where('prospect_id', $prospect->id)->sole();
            $this->assertSame('ERP Requirement', $lead->opportunity_title);
            $this->assertSame('Wants a full ERP rollout across two branches.', $lead->requirement_details);

            $newCall = CallRecord::query()->where('id', '!=', $call->id)->sole();
            $this->assertSame(CallOutcome::RequirementIdentified, $newCall->outcome);
            $this->assertSame('Ravi Kumar', $newCall->contact_person_spoken_to);
        });
    }

    /**
     * No auto-association by Prospect — a second Call from the same
     * Prospect through this same destination-specific modal creates a
     * SECOND, fully independent Lead, never reusing or attaching to the
     * first (matches the existing, already-established
     * MultipleLeadsPerCompanyTest precedent).
     */
    public function test_a_second_call_to_lead_transition_for_the_same_prospect_creates_an_independent_lead(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

            $board = app(PipelineBoard::class);
            $callA = $this->makeCall($user, $prospect);

            $this->invokePerformCrossDrop(
                $board,
                ['sourceResource' => 'call', 'sourceId' => $callA->id, 'destResource' => 'lead', 'destStage' => 'requirement_collection'],
                ['called_at' => now(), 'lead_opportunity_title' => 'Inventory Automation', 'notes' => 'First requirement.'],
            );

            $callB = $this->makeCall($user, $prospect);

            $this->invokePerformCrossDrop(
                $board,
                ['sourceResource' => 'call', 'sourceId' => $callB->id, 'destResource' => 'lead', 'destStage' => 'requirement_collection'],
                ['called_at' => now(), 'lead_opportunity_title' => 'Cybersecurity Assessment', 'notes' => 'Second, unrelated requirement.'],
            );

            $this->assertSame(2, Lead::query()->where('prospect_id', $prospect->id)->count());
            $titles = Lead::query()->where('prospect_id', $prospect->id)->pluck('opportunity_title')->sort()->values()->all();
            $this->assertSame(['Cybersecurity Assessment', 'Inventory Automation'], $titles);
        });
    }

    public function test_the_live_modal_requires_opportunity_title_and_requirement_details(): void
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
                    'destResource' => 'lead',
                    'destStage' => 'requirement_collection',
                ])
                ->setActionData([])
                ->callMountedAction()
                ->assertHasActionErrors(['lead_opportunity_title', 'notes']);

            $this->assertSame(0, Lead::query()->count());
        });
    }
}
