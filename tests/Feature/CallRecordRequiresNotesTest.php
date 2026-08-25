<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Filament\Resources\CallRecordResource\Pages\CreateCallRecord;
use App\Models\CallRecord;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Notes-required coverage broadened from just CallOutcome::Others (see
 * CallRecordOthersNotesTest, which still passes since Others is a subset of
 * this) to every outcome where a real conversation actually happened
 * (CallOutcome::requiresNotes()). This file exercises outcomes beyond
 * Others — AppointmentSet and RequirementIdentified as representative
 * "routes somewhere" outcomes — plus confirms all three "never connected"
 * outcomes stay exempt.
 */
class CallRecordRequiresNotesTest extends TestCase
{
    use RefreshDatabase;

    private function baseFormData(array $overrides = []): array
    {
        $prospect = Prospect::factory()->create();

        return array_merge([
            'prospect_id' => $prospect->id,
            'called_at' => now()->format('Y-m-d H:i:s'),
            'outcome' => CallOutcome::NoAnswer->value,
        ], $overrides);
    }

    public function test_appointment_set_without_notes_fails_validation(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateCallRecord::class)
            ->fillForm($this->baseFormData([
                'outcome' => CallOutcome::AppointmentSet->value,
                'appointment_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'notes' => null,
            ]))
            ->call('create')
            ->assertHasFormErrors(['notes']);

        $this->assertDatabaseCount('call_records', 0);
    }

    public function test_appointment_set_with_notes_succeeds(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateCallRecord::class)
            ->fillForm($this->baseFormData([
                'outcome' => CallOutcome::AppointmentSet->value,
                'appointment_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'notes' => 'Agreed to a site visit next week.',
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(CallOutcome::AppointmentSet, CallRecord::sole()->outcome);
    }

    public function test_requirement_identified_without_notes_fails_validation(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateCallRecord::class)
            ->fillForm($this->baseFormData([
                'outcome' => CallOutcome::RequirementIdentified->value,
                'appointment_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'notes' => null,
            ]))
            ->call('create')
            ->assertHasFormErrors(['notes']);

        $this->assertDatabaseCount('call_records', 0);
    }

    public function test_requirement_identified_with_notes_succeeds(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateCallRecord::class)
            ->fillForm($this->baseFormData([
                'outcome' => CallOutcome::RequirementIdentified->value,
                'appointment_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'notes' => 'Interested in a full plant rollout.',
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(CallOutcome::RequirementIdentified, CallRecord::sole()->outcome);
    }

    #[DataProvider('neverConnectedOutcomes')]
    public function test_a_never_connected_outcome_does_not_require_notes(CallOutcome $outcome): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateCallRecord::class)
            ->fillForm($this->baseFormData([
                'outcome' => $outcome->value,
                'follow_up_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'notes' => null,
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('call_records', 1);
    }

    public static function neverConnectedOutcomes(): array
    {
        return [
            'No Answer' => [CallOutcome::NoAnswer],
            'Switched Off' => [CallOutcome::SwitchedOff],
            'Not Reachable' => [CallOutcome::NotReachable],
        ];
    }

    /**
     * Defense in depth: the model guard must reject a requires-notes outcome
     * even when it bypasses the Filament form entirely, for outcomes beyond
     * the already-covered Others.
     */
    public function test_model_guard_rejects_an_appointment_set_call_record_without_notes(): void
    {
        $this->expectException(\LogicException::class);

        $prospect = Prospect::factory()->create();

        CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'called_at' => now(),
            'outcome' => CallOutcome::AppointmentSet,
            'appointment_at' => now()->addDay(),
        ]);
    }

    public function test_model_guard_allows_a_switched_off_call_record_without_notes(): void
    {
        $prospect = Prospect::factory()->create();

        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'called_at' => now(),
            'outcome' => CallOutcome::SwitchedOff,
        ]);

        $this->assertSame(CallOutcome::SwitchedOff, $call->fresh()->outcome);
    }
}
