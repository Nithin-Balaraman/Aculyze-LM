<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Filament\Pages\PipelineBoard;
use App\Filament\Resources\CallRecordResource\Pages\CreateCallRecord;
use App\Models\Appointment;
use App\Models\CallRecord;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Services\CallRoutingService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pre-existing atomicity bug fix (independent of the Pipeline V2 destination/
 * prerequisite investigation that found it): CallRecord::create() at three
 * sites — CreateCallRecord (Filament's default handleRecordCreation()),
 * PipelineBoard::performCreateCompany(), and PipelineBoard::logNewCall() —
 * previously ran with no outer transaction of its own. CallRecordObserver::
 * created() -> CallRoutingService::route() opens its OWN DB::transaction()
 * the instant the Call Record is saved, so a routing failure only rolled
 * back the routing — the already-committed Call Record survived, with
 * processed_at left null, while the user was shown a failure. All three
 * sites now wrap creation in DB::transaction(), the same composition
 * FollowUp::completeWithCall() already relied on (the Observer's nested
 * transaction becomes a savepoint of the outer one).
 *
 * The routing failure below is forced via a container-rebound
 * CallRoutingService double that throws unconditionally — this is the only
 * reliable way to simulate "routing fails after the Call Record insert has
 * already begun" without touching production code: every real routing path
 * (createFollowUp/createAppointment/createLead) already fully satisfies its
 * own downstream model's guards by construction, so there is no organic,
 * reachable-through-the-public-API failure to trigger instead.
 */
class CallRecordCreationAtomicityTest extends TestCase
{
    use RefreshDatabase;

    private function bindFailingCallRoutingService(): void
    {
        $this->app->bind(CallRoutingService::class, fn () => new class extends CallRoutingService
        {
            public function route(CallRecord $callRecord): void
            {
                throw new \RuntimeException('Forced routing failure for atomicity test.');
            }
        });
    }

    // --- Site 1: CreateCallRecord (Filament resource create page) ---

    public function test_create_call_record_page_success_path_is_unchanged(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $prospect = Prospect::factory()->create();

        Livewire::test(CreateCallRecord::class)
            ->fillForm([
                'prospect_id' => $prospect->id,
                'called_at' => now()->format('Y-m-d H:i:s'),
                'outcome' => CallOutcome::AppointmentSet->value,
                'appointment_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'notes' => 'Agreed to a site visit.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('call_records', 1);
        $call = CallRecord::query()->sole();
        $this->assertNotNull($call->processed_at);
        $this->assertSame(1, Appointment::query()->where('prospect_id', $prospect->id)->count());
    }

    public function test_create_call_record_page_rolls_back_entirely_when_routing_fails(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $prospect = Prospect::factory()->create();

        $this->bindFailingCallRoutingService();

        try {
            Livewire::test(CreateCallRecord::class)
                ->fillForm([
                    'prospect_id' => $prospect->id,
                    'called_at' => now()->format('Y-m-d H:i:s'),
                    'outcome' => CallOutcome::AppointmentSet->value,
                    'appointment_at' => now()->addDay()->format('Y-m-d H:i:s'),
                    'notes' => 'Agreed to a site visit.',
                ])
                ->call('create');

            $this->fail('Expected the forced routing failure to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Forced routing failure for atomicity test.', $e->getMessage());
        }

        $this->assertDatabaseCount('call_records', 0);
        $this->assertDatabaseCount('appointments', 0);
    }

    // --- Site 2: PipelineBoard::performCreateCompany() ("+ Log a call") ---

    private function invokePerformCreateCompany(PipelineBoard $board, array $data): void
    {
        $method = new \ReflectionMethod($board, 'performCreateCompany');
        $method->setAccessible(true);
        $method->invoke($board, $data);
    }

    public function test_log_a_call_action_success_path_is_unchanged(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $prospect = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id]);

        $this->invokePerformCreateCompany(app(PipelineBoard::class), [
            'prospect_id' => $prospect->id,
            'called_at' => now(),
            'outcome' => CallOutcome::RequirementIdentified->value,
            'notes' => 'Interested in a full rollout.',
        ]);

        $this->assertDatabaseCount('call_records', 1);
        $call = CallRecord::query()->sole();
        $this->assertNotNull($call->processed_at);
        $this->assertSame(1, Lead::query()->where('prospect_id', $prospect->id)->count());
    }

    public function test_log_a_call_action_rolls_back_entirely_when_routing_fails(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $prospect = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id]);

        $this->bindFailingCallRoutingService();

        try {
            $this->invokePerformCreateCompany(app(PipelineBoard::class), [
                'prospect_id' => $prospect->id,
                'called_at' => now(),
                'outcome' => CallOutcome::RequirementIdentified->value,
                'notes' => 'Interested in a full rollout.',
            ]);

            $this->fail('Expected the forced routing failure to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Forced routing failure for atomicity test.', $e->getMessage());
        }

        $this->assertDatabaseCount('call_records', 0);
        $this->assertDatabaseCount('leads', 0);
    }

    // --- Site 3: PipelineBoard::logNewCall() (dragging a Call card onto another lane) ---

    private function invokePerformCrossDrop(PipelineBoard $board, array $arguments, array $data = []): void
    {
        $method = new \ReflectionMethod($board, 'performCrossDrop');
        $method->setAccessible(true);
        $method->invoke($board, $arguments, $data);
    }

    /**
     * Success-path coverage for this exact site already exists in full
     * (three destinations, plus "the dragged card is never mutated") in
     * PipelineBoardCallCrossDropTest — not duplicated here.
     */
    public function test_call_cross_drop_rolls_back_entirely_when_routing_fails(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $originalCall = CallRecord::create([
                'prospect_id' => $prospect->id,
                'user_id' => $user->id,
                'called_at' => now()->subDay(),
                'outcome' => CallOutcome::NoAnswer,
            ]);

            $this->bindFailingCallRoutingService();

            try {
                $this->invokePerformCrossDrop(
                    app(PipelineBoard::class),
                    ['sourceResource' => 'call', 'sourceId' => $originalCall->id, 'destResource' => 'lead', 'destStage' => 'requirement_collection'],
                    ['outcome' => CallOutcome::RequirementIdentified->value, 'notes' => 'Interested.', 'called_at' => now()],
                );

                $this->fail('Expected the forced routing failure to propagate.');
            } catch (\RuntimeException $e) {
                $this->assertSame('Forced routing failure for atomicity test.', $e->getMessage());
            }

            // The originally-dragged Call Record survives untouched (it was
            // never part of this write); no NEW Call Record and no Lead
            // were left behind by the failed attempt to log a second one.
            $this->assertDatabaseCount('call_records', 1);
            $this->assertSame($originalCall->id, CallRecord::query()->sole()->id);
            $this->assertDatabaseCount('leads', 0);
        });
    }
}
