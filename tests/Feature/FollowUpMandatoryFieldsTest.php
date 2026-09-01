<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Enums\FollowUpStatus;
use App\Filament\Resources\FollowUpResource\Pages\CreateFollowUp;
use App\Filament\Resources\FollowUpResource\Pages\EditFollowUp;
use App\Models\CallRecord;
use App\Models\FollowUp;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Mandatory Fields batch: Reason and Follow Up At are required on
 * Follow-Ups. Reason is checked unconditionally (every write path already
 * populates it). Follow Up At is exempt only for the very first insert
 * coming from CallRoutingService (which often doesn't know a callback time
 * yet) — any later save that actually sets it blank is still rejected, but
 * unrelated updates (Completed/Close) that never touch it are unaffected.
 */
class FollowUpMandatoryFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function baseFormData(array $overrides = []): array
    {
        $prospect = Prospect::factory()->create();

        return array_merge([
            'prospect_id' => $prospect->id,
            'follow_up_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'reason' => 'Call back next week',
            'status' => FollowUpStatus::Pending->value,
        ], $overrides);
    }

    // --- Form-level ---

    public function test_creating_a_follow_up_without_reason_fails_validation(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateFollowUp::class)
            ->fillForm($this->baseFormData(['reason' => null]))
            ->call('create')
            ->assertHasFormErrors(['reason']);

        $this->assertDatabaseCount('follow_ups', 0);
    }

    public function test_creating_a_follow_up_without_follow_up_at_fails_validation(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateFollowUp::class)
            ->fillForm($this->baseFormData(['follow_up_at' => null]))
            ->call('create')
            ->assertHasFormErrors(['follow_up_at']);

        $this->assertDatabaseCount('follow_ups', 0);
    }

    public function test_creating_a_follow_up_with_reason_and_follow_up_at_succeeds(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateFollowUp::class)
            ->fillForm($this->baseFormData())
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('follow_ups', 1);
    }

    public function test_editing_an_auto_routed_follow_up_to_blank_follow_up_at_fails_validation(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        // Phase 3: No Answer no longer creates a Follow-Up — Callback
        // Requested is the simplest outcome that still unconditionally
        // does, and (at the raw model level, bypassing the form) can still
        // be created with a blank follow_up_at.
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $employee->id,
            'called_at' => now(),
            'outcome' => CallOutcome::CallbackRequested,
            'notes' => 'Asked to call back later.',
        ]);
        $followUp = $call->fresh()->followUp;
        $this->assertNull($followUp->follow_up_at);

        $this->actingAs($employee);

        Livewire::test(EditFollowUp::class, ['record' => $followUp->getRouteKey()])
            ->fillForm(['follow_up_at' => null])
            ->call('save')
            ->assertHasFormErrors(['follow_up_at']);
    }

    // --- Model-level guard ---

    public function test_auto_routed_follow_up_can_be_created_without_follow_up_at(): void
    {
        $prospect = Prospect::factory()->create();

        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'called_at' => now(),
            'outcome' => CallOutcome::CallbackRequested,
            'notes' => 'Asked to call back later.',
        ]);

        $followUp = $call->fresh()->followUp;
        $this->assertNotNull($followUp);
        $this->assertNull($followUp->follow_up_at);
        $this->assertSame('Callback Requested', $followUp->reason);
    }

    public function test_model_guard_rejects_a_manually_created_follow_up_without_reason(): void
    {
        $this->expectException(\LogicException::class);

        $prospect = Prospect::factory()->create();

        FollowUp::create([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'follow_up_at' => now()->addDay(),
            'status' => FollowUpStatus::Pending,
        ]);
    }

    public function test_model_guard_rejects_a_manually_created_follow_up_without_follow_up_at(): void
    {
        $this->expectException(\LogicException::class);

        $prospect = Prospect::factory()->create();

        FollowUp::create([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'reason' => 'Manually added',
            'status' => FollowUpStatus::Pending,
        ]);
    }

    public function test_model_guard_rejects_explicitly_blanking_follow_up_at_on_an_existing_record(): void
    {
        $prospect = Prospect::factory()->create();
        $followUp = FollowUp::create([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'follow_up_at' => now()->addDay(),
            'reason' => 'Manually added',
            'status' => FollowUpStatus::Pending,
        ]);

        $this->expectException(\LogicException::class);

        $followUp->update(['follow_up_at' => null]);
    }

    /**
     * Regression guard: the Completed/Close row actions update `status`
     * only and must keep working on an auto-routed Follow-Up whose
     * follow_up_at is still blank — they must not be blocked by a guard
     * meant only for the form/manual-creation paths.
     */
    public function test_updating_status_on_an_auto_routed_follow_up_with_blank_follow_up_at_still_succeeds(): void
    {
        $prospect = Prospect::factory()->create();
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'called_at' => now(),
            'outcome' => CallOutcome::CallbackRequested,
            'notes' => 'Asked to call back later.',
        ]);
        $followUp = $call->fresh()->followUp;
        $this->assertNull($followUp->follow_up_at);

        $followUp->update(['status' => FollowUpStatus::Cancelled]);

        $this->assertSame(FollowUpStatus::Cancelled, $followUp->fresh()->status);
    }
}
