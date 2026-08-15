<?php

namespace Tests\Feature;

use App\Enums\FollowUpStatus;
use App\Filament\Resources\FollowUpResource;
use App\Filament\Resources\FollowUpResource\Pages\ListFollowUps;
use App\Models\FollowUp;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 2 item #3: History and Lost group by company (collapsible, headers
 * show a per-company count + most recent date), Pending stays a flat list —
 * see FollowUpResource::table()'s ->groups()/->defaultGroup() and
 * ListFollowUps::updatedActiveTab().
 */
class FollowUpHistoryGroupingTest extends TestCase
{
    use RefreshDatabase;

    private function makeFollowUp(Prospect $prospect, User $owner, FollowUpStatus $status, ?string $followUpAt = null): FollowUp
    {
        return FollowUp::create([
            'prospect_id' => $prospect->id,
            'user_id' => $owner->id,
            'follow_up_at' => $followUpAt ?? now(),
            'reason' => 'Callback later',
            'status' => $status,
        ]);
    }

    public function test_history_tab_groups_by_company_by_default(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee);

        Livewire::test(ListFollowUps::class)
            ->set('activeTab', 'history')
            ->assertSet('tableGrouping', FollowUpResource::GROUP_BY_COMPANY);
    }

    public function test_lost_tab_groups_by_company_by_default(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee);

        Livewire::test(ListFollowUps::class)
            ->set('activeTab', 'lost')
            ->assertSet('tableGrouping', FollowUpResource::GROUP_BY_COMPANY);
    }

    public function test_pending_tab_is_not_grouped(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee);

        Livewire::test(ListFollowUps::class)
            ->set('activeTab', 'pending')
            ->assertSet('tableGrouping', null);
    }

    public function test_switching_back_to_pending_after_history_clears_the_grouping(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee);

        Livewire::test(ListFollowUps::class)
            ->set('activeTab', 'history')
            ->assertSet('tableGrouping', FollowUpResource::GROUP_BY_COMPANY)
            ->set('activeTab', 'pending')
            ->assertSet('tableGrouping', null);
    }

    public function test_group_header_shows_follow_up_count_and_most_recent_date_for_the_active_tabs_statuses(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id, 'company_name' => 'Acme Corp']);

        // Two History-eligible follow-ups (Completed + Cancelled) plus one
        // Pending one that must NOT count toward the History group's tally.
        $this->makeFollowUp($prospect, $employee, FollowUpStatus::Completed, '2026-01-10 10:00:00');
        $this->makeFollowUp($prospect, $employee, FollowUpStatus::Cancelled, '2026-03-15 10:00:00');
        $this->makeFollowUp($prospect, $employee, FollowUpStatus::Pending, '2026-06-01 10:00:00');

        $this->actingAs($employee);

        Livewire::test(ListFollowUps::class)
            ->set('activeTab', 'history')
            ->assertSee('Acme Corp')
            ->assertSee('2 follow-ups')
            ->assertSee('15 Mar 2026');
    }

    public function test_group_header_on_lost_tab_only_counts_cancelled(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id, 'company_name' => 'Beta Industries']);

        $this->makeFollowUp($prospect, $employee, FollowUpStatus::Completed, '2026-01-10 10:00:00');
        $this->makeFollowUp($prospect, $employee, FollowUpStatus::Cancelled, '2026-02-20 10:00:00');

        $this->actingAs($employee);

        Livewire::test(ListFollowUps::class)
            ->set('activeTab', 'lost')
            ->assertSee('Beta Industries')
            ->assertSee('1 follow-up')
            ->assertDontSee('1 follow-ups')
            ->assertSee('20 Feb 2026');
    }

    public function test_grouping_does_not_hide_any_records_it_just_collapses_them(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);

        $first = $this->makeFollowUp($prospect, $employee, FollowUpStatus::Completed);
        $second = $this->makeFollowUp($prospect, $employee, FollowUpStatus::Cancelled);

        $this->actingAs($employee);

        Livewire::test(ListFollowUps::class)
            ->set('activeTab', 'history')
            ->assertCanSeeTableRecords([$first, $second]);
    }

    /**
     * Filament's Group::collapsible() only adds the toggle — groups start
     * expanded by default, with no PHP-level "start collapsed" option (see
     * ListFollowUps::getFooter()'s doc comment for the full explanation).
     * The client-side script that auto-collapses on load simulates a click
     * on each group header, so this only confirms the script itself is
     * present on the page — the actual collapsed *rendering* is client-side
     * DOM state Livewire's server-side test harness can't observe.
     */
    public function test_the_auto_collapse_script_is_present_on_the_page(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee);

        Livewire::test(ListFollowUps::class)
            ->assertSee('collapseExpandedFollowUpGroups', false)
            ->assertSee('fi-ta-group-header', false);
    }

    public function test_switching_tabs_dispatches_the_grouping_reset_event_for_the_auto_collapse_script(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee);

        Livewire::test(ListFollowUps::class)
            ->set('activeTab', 'history')
            ->assertDispatched('follow-ups-grouping-reset');
    }
}
