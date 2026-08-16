<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Filament\Resources\CallRecordResource\Pages\CreateCallRecord;
use App\Filament\Resources\CallRecordResource\Pages\EditCallRecord;
use App\Models\CallRecord;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A Call Record may only be saved with outcome Others when Notes is
 * genuinely present — mirrors LeadValidatedNotesTest (Notes required when
 * Lead stage is Validated). Others routes nowhere (see CallOutcome::
 * routesNowhere()), so Notes is the only record of what happened.
 */
class CallRecordOthersNotesTest extends TestCase
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

    private function makeCall(User $owner, CallOutcome $outcome, ?string $notes = null): CallRecord
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);

        return CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $owner->id,
            'called_at' => now(),
            'outcome' => $outcome,
            'notes' => $notes,
        ]);
    }

    public function test_non_others_outcome_can_be_created_without_notes(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateCallRecord::class)
            ->fillForm($this->baseFormData([
                'outcome' => CallOutcome::NoAnswer->value,
                'follow_up_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'notes' => null,
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('call_records', 1);
    }

    public function test_creating_an_others_call_record_without_notes_fails_validation(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateCallRecord::class)
            ->fillForm($this->baseFormData(['outcome' => CallOutcome::Others->value, 'notes' => null]))
            ->call('create')
            ->assertHasFormErrors(['notes']);

        $this->assertDatabaseCount('call_records', 0);
    }

    /**
     * Regression guard: fillForm() seeds raw scalars, but a real Select
     * interaction rehydrates $get('outcome') as the actual CallOutcome enum
     * case, not its string value — mirrors the same guard in
     * LeadValidatedNotesTest.
     */
    public function test_creating_an_others_call_record_without_notes_fails_validation_when_outcome_is_set_as_the_hydrated_enum_case(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateCallRecord::class)
            ->fillForm($this->baseFormData(['notes' => null]))
            ->set('data.outcome', CallOutcome::Others)
            ->call('create')
            ->assertHasFormErrors(['notes']);

        $this->assertDatabaseCount('call_records', 0);
    }

    public function test_creating_an_others_call_record_with_whitespace_only_notes_fails_validation(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateCallRecord::class)
            ->fillForm($this->baseFormData(['outcome' => CallOutcome::Others->value, 'notes' => "   \n\t  "]))
            ->call('create')
            ->assertHasFormErrors(['notes']);

        $this->assertDatabaseCount('call_records', 0);
    }

    public function test_creating_an_others_call_record_with_valid_notes_succeeds(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateCallRecord::class)
            ->fillForm($this->baseFormData(['outcome' => CallOutcome::Others->value, 'notes' => 'Wrong number, belongs to a different company now.']))
            ->call('create')
            ->assertHasNoFormErrors();

        $call = CallRecord::sole();
        $this->assertSame(CallOutcome::Others, $call->outcome);
        $this->assertSame('Wrong number, belongs to a different company now.', $call->notes);
    }

    public function test_editing_a_call_record_into_others_without_notes_fails_validation(): void
    {
        $admin = User::factory()->admin()->create();
        $call = $this->makeCall($admin, CallOutcome::NoAnswer, null);
        $this->actingAs($admin);

        Livewire::test(EditCallRecord::class, ['record' => $call->getRouteKey()])
            ->fillForm(['outcome' => CallOutcome::Others->value, 'notes' => null])
            ->call('save')
            ->assertHasFormErrors(['notes']);

        $this->assertSame(CallOutcome::NoAnswer, $call->fresh()->outcome);
    }

    public function test_editing_a_call_record_into_others_with_notes_succeeds(): void
    {
        $admin = User::factory()->admin()->create();
        $call = $this->makeCall($admin, CallOutcome::NoAnswer, null);
        $this->actingAs($admin);

        Livewire::test(EditCallRecord::class, ['record' => $call->getRouteKey()])
            ->fillForm(['outcome' => CallOutcome::Others->value, 'notes' => 'Turned out to be a wrong lead entirely.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $call->refresh();
        $this->assertSame(CallOutcome::Others, $call->outcome);
        $this->assertSame('Turned out to be a wrong lead entirely.', $call->notes);
    }

    /**
     * Defense in depth: the model guard must reject an Others save even
     * when it bypasses the Filament form entirely.
     */
    public function test_model_guard_rejects_an_others_call_record_without_notes(): void
    {
        $this->expectException(\LogicException::class);

        $prospect = Prospect::factory()->create();

        CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'called_at' => now(),
            'outcome' => CallOutcome::Others,
        ]);
    }
}
