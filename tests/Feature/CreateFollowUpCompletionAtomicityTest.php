<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Enums\FollowUpStatus;
use App\Filament\Resources\FollowUpResource\Pages\CreateFollowUp;
use App\Models\CallRecord;
use App\Models\FollowUp;
use App\Models\Prospect;
use App\Models\User;
use App\Services\CallRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pre-existing atomicity bug fix: CreateFollowUp::handleRecordCreation()
 * could pick Status = Completed right at creation, which previously ran as
 * three separate, un-transacted writes — insert the Follow-Up as Pending,
 * create a Call Record for it, then flip status to Completed — reimplementing
 * what FollowUp::completeWithCall() already does safely and atomically for
 * the identical Edit-page scenario. The fix removes the duplicated inline
 * logic entirely in favor of calling completeWithCall() itself, with the
 * whole sequence (including the initial Pending insert) wrapped in one outer
 * DB::transaction() — completeWithCall()'s own transaction nests as a
 * savepoint of it, so a failure anywhere downstream now rolls back the
 * Follow-Up insert too, leaving nothing behind.
 */
class CreateFollowUpCompletionAtomicityTest extends TestCase
{
    use RefreshDatabase;

    private function completingFormData(Prospect $prospect, array $overrides = []): array
    {
        return array_merge([
            'prospect_id' => $prospect->id,
            'follow_up_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'reason' => 'Call back next week',
            'status' => FollowUpStatus::Completed->value,
            'outcome' => CallOutcome::NoCurrentRequirement->value,
            'call_notes' => 'Customer said not interested right now.',
        ], $overrides);
    }

    public function test_completing_at_creation_succeeds_and_matches_prior_behavior(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $prospect = Prospect::factory()->create();

        Livewire::test(CreateFollowUp::class)
            ->fillForm($this->completingFormData($prospect))
            ->call('create')
            ->assertHasNoFormErrors();

        $followUp = FollowUp::query()->where('prospect_id', $prospect->id)->sole();
        $this->assertSame(FollowUpStatus::Completed, $followUp->status);

        $callRecord = CallRecord::query()->where('follow_up_id', $followUp->id)->sole();
        $this->assertSame(CallOutcome::NoCurrentRequirement, $callRecord->outcome);
        $this->assertNotNull($callRecord->processed_at);
    }

    public function test_completing_at_creation_rolls_back_entirely_when_the_call_record_routing_fails(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $prospect = Prospect::factory()->create();

        $this->app->bind(CallRoutingService::class, fn () => new class extends CallRoutingService
        {
            public function route(CallRecord $callRecord): void
            {
                throw new \RuntimeException('Forced routing failure for atomicity test.');
            }
        });

        try {
            Livewire::test(CreateFollowUp::class)
                ->fillForm($this->completingFormData($prospect))
                ->call('create');

            $this->fail('Expected the forced routing failure to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Forced routing failure for atomicity test.', $e->getMessage());
        }

        // No partially-created Follow-Up (still Pending, no Call Record
        // behind it) and no orphan Call Record either — the whole attempt
        // leaves nothing behind.
        $this->assertDatabaseCount('follow_ups', 0);
        $this->assertDatabaseCount('call_records', 0);
    }

    /**
     * Regression guard: a plain (non-completing) creation — the common
     * case — must still work exactly as before; it never touches
     * completeWithCall() or the outer transaction's nested savepoint at
     * all.
     */
    public function test_plain_pending_creation_is_unaffected(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $prospect = Prospect::factory()->create();

        Livewire::test(CreateFollowUp::class)
            ->fillForm([
                'prospect_id' => $prospect->id,
                'follow_up_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'reason' => 'Call back next week',
                'status' => FollowUpStatus::Pending->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $followUp = FollowUp::query()->where('prospect_id', $prospect->id)->sole();
        $this->assertSame(FollowUpStatus::Pending, $followUp->status);
        $this->assertDatabaseCount('call_records', 0);
    }
}
