<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Filament\Resources\CallRecordResource\Pages\ListCallRecords;
use App\Models\CallRecord;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "All" / "History" tabs on the Call Records page, added alongside the
 * Future Opportunity routing fix (see CallRoutingTest::
 * test_future_opportunity_routes_nowhere). "History" narrows to calls whose
 * outcome doesn't route anywhere else — currently just Future Opportunity —
 * and intentionally overlaps "All", same as "Lost" overlaps "History"
 * elsewhere in this app.
 */
class CallRecordTabsTest extends TestCase
{
    use RefreshDatabase;

    private function makeCall(User $owner, CallOutcome $outcome): CallRecord
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);

        return CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $owner->id,
            'called_at' => now(),
            'outcome' => $outcome,
        ]);
    }

    public function test_all_tab_shows_every_call_regardless_of_outcome(): void
    {
        $employee = User::factory()->create();
        $noAnswer = $this->makeCall($employee, CallOutcome::NoAnswer);
        $futureOpportunity = $this->makeCall($employee, CallOutcome::FutureOpportunity);

        $this->actingAs($employee);

        Livewire::test(ListCallRecords::class)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$noAnswer, $futureOpportunity]);
    }

    public function test_history_tab_shows_only_future_opportunity_calls(): void
    {
        $employee = User::factory()->create();
        $noAnswer = $this->makeCall($employee, CallOutcome::NoAnswer);
        $appointmentSet = $this->makeCall($employee, CallOutcome::AppointmentSet);
        $futureOpportunity = $this->makeCall($employee, CallOutcome::FutureOpportunity);

        $this->actingAs($employee);

        Livewire::test(ListCallRecords::class)
            ->set('activeTab', 'history')
            ->assertCanSeeTableRecords([$futureOpportunity])
            ->assertCanNotSeeTableRecords([$noAnswer, $appointmentSet]);
    }

    public function test_future_opportunity_call_still_appears_in_all_after_history_exists(): void
    {
        $employee = User::factory()->create();
        $futureOpportunity = $this->makeCall($employee, CallOutcome::FutureOpportunity);

        $this->actingAs($employee);

        Livewire::test(ListCallRecords::class)
            ->set('activeTab', 'history')
            ->assertCanSeeTableRecords([$futureOpportunity]);

        Livewire::test(ListCallRecords::class)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$futureOpportunity]);
    }

    public function test_employee_only_sees_their_own_calls_in_either_tab(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $ownerAll = $this->makeCall($owner, CallOutcome::NoAnswer);
        $ownerHistory = $this->makeCall($owner, CallOutcome::FutureOpportunity);

        $this->actingAs($intruder);

        Livewire::test(ListCallRecords::class)
            ->set('activeTab', 'all')
            ->assertCanNotSeeTableRecords([$ownerAll, $ownerHistory]);

        Livewire::test(ListCallRecords::class)
            ->set('activeTab', 'history')
            ->assertCanNotSeeTableRecords([$ownerHistory]);
    }

    public function test_admin_sees_every_employees_calls_in_both_tabs(): void
    {
        $admin = User::factory()->admin()->create();
        $nithin = User::factory()->create();
        $kural = User::factory()->create();
        $nithinCall = $this->makeCall($nithin, CallOutcome::NoAnswer);
        $kuralHistoryCall = $this->makeCall($kural, CallOutcome::FutureOpportunity);

        $this->actingAs($admin);

        Livewire::test(ListCallRecords::class)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$nithinCall, $kuralHistoryCall]);

        Livewire::test(ListCallRecords::class)
            ->set('activeTab', 'history')
            ->assertCanSeeTableRecords([$kuralHistoryCall]);
    }
}
