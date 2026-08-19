<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Enums\FollowUpStatus;
use App\Filament\Resources\FollowUpResource\Pages\ListFollowUps;
use App\Models\CallRecord;
use App\Models\FollowUp;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The "Summary" button rides along inside the History/Lost group header's
 * description text (see FollowUpResource::groupSummary()) and opens
 * ListFollowUps::summaryAction() — a read-only, page-level action (not a
 * table row action; see that method's comment for why) listing every
 * Completed/Cancelled Follow-Up for that company
 * (FollowUpResource::companyFollowUpHistory()).
 *
 * It's never placed in getHeaderActions() or any other rendered slot, so
 * these tests trigger it with the raw mountAction() Livewire test helper
 * (passing the target Follow-Up as the `followUpId` argument, exactly
 * like the button's own wire:click does) rather than callAction(), which
 * asserts the action is visible in some rendered location first — not
 * applicable to an action that's intentionally never auto-rendered.
 */
class FollowUpSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompletedFollowUp(Prospect $prospect, User $owner, string $calledAt, string $notes): FollowUp
    {
        $followUp = FollowUp::create([
            'prospect_id' => $prospect->id,
            'user_id' => $owner->id,
            'follow_up_at' => $calledAt,
            'reason' => 'Callback later',
            'status' => FollowUpStatus::Completed,
        ]);

        CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $owner->id,
            'called_at' => $calledAt,
            'outcome' => CallOutcome::RequirementIdentified,
            'notes' => $notes,
            'follow_up_id' => $followUp->id,
        ]);

        return $followUp;
    }

    private function makeCancelledFollowUp(Prospect $prospect, User $owner, string $updatedAt, string $notes): FollowUp
    {
        $followUp = FollowUp::create([
            'prospect_id' => $prospect->id,
            'user_id' => $owner->id,
            'follow_up_at' => now(),
            'reason' => 'Callback later',
            'status' => FollowUpStatus::Cancelled,
            'notes' => $notes,
        ]);

        // FollowUp has no dedicated cancelled_at column (see
        // companyFollowUpHistory()'s comment) — updated_at is the only
        // "when" available, so it has to be pinned directly here to make
        // ordering assertions deterministic.
        $followUp->timestamps = false;
        $followUp->forceFill(['updated_at' => $updatedAt])->save();

        return $followUp->fresh();
    }

    public function test_summary_button_appears_only_on_the_history_tab_not_pending(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);

        $this->makeCompletedFollowUp($prospect, $employee, '2026-01-05 10:00:00', 'Reached them, moving forward.');
        FollowUp::create([
            'prospect_id' => $prospect->id,
            'user_id' => $employee->id,
            'follow_up_at' => now()->addDay(),
            'reason' => 'Callback later',
            'status' => FollowUpStatus::Pending,
        ]);

        $this->actingAs($employee);

        Livewire::test(ListFollowUps::class)
            ->set('activeTab', 'history')
            ->assertSeeHtml('follow-up-summary-button');

        Livewire::test(ListFollowUps::class)
            ->set('activeTab', 'pending')
            ->assertDontSeeHtml('follow-up-summary-button');
    }

    public function test_summary_modal_lists_completed_and_cancelled_entries_most_recent_first_with_correct_dates_and_notes(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);

        $oldest = $this->makeCompletedFollowUp($prospect, $employee, '2026-01-01 09:00:00', 'Oldest completed call.');
        $this->makeCancelledFollowUp($prospect, $employee, '2026-01-10 12:00:00', 'Cancelled — no longer interested.');
        $this->makeCompletedFollowUp($prospect, $employee, '2026-01-15 15:30:00', 'Newest completed call.');

        $this->actingAs($employee);

        $test = Livewire::test(ListFollowUps::class)
            ->set('activeTab', 'history')
            ->mountAction('summary', ['followUpId' => $oldest->id]);

        $test->assertSee('Oldest completed call.')
            ->assertSee('Cancelled — no longer interested.')
            ->assertSee('Newest completed call.')
            ->assertSee(Carbon::parse('2026-01-15 15:30:00')->format('d M Y, h:i A'))
            ->assertSee(Carbon::parse('2026-01-01 09:00:00')->format('d M Y, h:i A'));

        // Most recent first: newest completed, then the cancellation, then
        // the oldest completed call.
        $test->assertSeeHtmlInOrder([
            'Newest completed call.',
            'Cancelled — no longer interested.',
            'Oldest completed call.',
        ]);
    }

    public function test_summary_modal_excludes_another_companys_follow_ups(): void
    {
        $employee = User::factory()->create();
        $companyA = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $companyB = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);

        $companyAFollowUp = $this->makeCompletedFollowUp($companyA, $employee, '2026-01-05 10:00:00', 'Company A note.');
        $this->makeCompletedFollowUp($companyB, $employee, '2026-01-06 10:00:00', 'Company B note.');

        $this->actingAs($employee);

        Livewire::test(ListFollowUps::class)
            ->set('activeTab', 'history')
            ->mountAction('summary', ['followUpId' => $companyAFollowUp->id])
            ->assertSee('Company A note.')
            ->assertDontSee('Company B note.');
    }

    public function test_summary_action_is_scoped_to_the_owning_employees_follow_ups(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);

        $ownersFollowUp = $this->makeCompletedFollowUp($prospect, $owner, '2026-01-05 10:00:00', 'Private note for the owner only.');

        $this->actingAs($intruder);

        // summaryAction()'s own summaryFollowUp() re-scopes the passed
        // followUpId through FollowUp::visibleTo(auth()->user()) — the
        // intruder's lookup comes back null, so the modal renders with an
        // empty history rather than the owner's data.
        Livewire::test(ListFollowUps::class)
            ->set('activeTab', 'history')
            ->mountAction('summary', ['followUpId' => $ownersFollowUp->id])
            ->assertDontSee('Private note for the owner only.');
    }
}
