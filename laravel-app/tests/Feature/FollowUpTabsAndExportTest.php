<?php

namespace Tests\Feature;

use App\Enums\FollowUpStatus;
use App\Filament\Resources\AppointmentResource\Pages\ListAppointments;
use App\Filament\Resources\FollowUpResource\Pages\ListFollowUps;
use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Filament\Resources\ProposalResource\Pages\ListProposals;
use App\Models\FollowUp;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * UX Fixes Batch Issue 3: Follow-Ups gets Pending/History tabs (one page,
 * no new resource/nav item) and an employee-accessible CSV export, scoped
 * server-side the same way the table itself already is.
 */
class FollowUpTabsAndExportTest extends TestCase
{
    use RefreshDatabase;

    private function makeFollowUp(User $owner, FollowUpStatus $status, string $reason = 'Callback later'): FollowUp
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);

        return FollowUp::create([
            'prospect_id' => $prospect->id,
            'user_id' => $owner->id,
            'follow_up_at' => now(),
            'reason' => $reason,
            'status' => $status,
        ]);
    }

    public function test_pending_tab_shows_only_pending_follow_ups(): void
    {
        $employee = User::factory()->create();
        $pending = $this->makeFollowUp($employee, FollowUpStatus::Pending);
        $completed = $this->makeFollowUp($employee, FollowUpStatus::Completed);
        $cancelled = $this->makeFollowUp($employee, FollowUpStatus::Cancelled);

        $this->actingAs($employee);

        Livewire::test(ListFollowUps::class)
            ->set('activeTab', 'pending')
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$completed, $cancelled]);
    }

    public function test_history_tab_shows_completed_and_cancelled_together_but_not_pending(): void
    {
        $employee = User::factory()->create();
        $pending = $this->makeFollowUp($employee, FollowUpStatus::Pending);
        $completed = $this->makeFollowUp($employee, FollowUpStatus::Completed);
        $cancelled = $this->makeFollowUp($employee, FollowUpStatus::Cancelled);

        $this->actingAs($employee);

        Livewire::test(ListFollowUps::class)
            ->set('activeTab', 'history')
            ->assertCanSeeTableRecords([$completed, $cancelled])
            ->assertCanNotSeeTableRecords([$pending]);
    }

    public function test_employee_only_sees_their_own_follow_ups_in_either_tab(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $ownerPending = $this->makeFollowUp($owner, FollowUpStatus::Pending);
        $ownerHistory = $this->makeFollowUp($owner, FollowUpStatus::Completed);

        $this->actingAs($intruder);

        Livewire::test(ListFollowUps::class)
            ->set('activeTab', 'pending')
            ->assertCanNotSeeTableRecords([$ownerPending]);

        Livewire::test(ListFollowUps::class)
            ->set('activeTab', 'history')
            ->assertCanNotSeeTableRecords([$ownerHistory]);
    }

    public function test_admin_sees_every_employees_follow_ups_in_both_tabs(): void
    {
        $admin = User::factory()->admin()->create();
        $nithin = User::factory()->create();
        $kural = User::factory()->create();
        $nithinPending = $this->makeFollowUp($nithin, FollowUpStatus::Pending);
        $kuralHistory = $this->makeFollowUp($kural, FollowUpStatus::Cancelled);

        $this->actingAs($admin);

        Livewire::test(ListFollowUps::class)
            ->set('activeTab', 'pending')
            ->assertCanSeeTableRecords([$nithinPending]);

        Livewire::test(ListFollowUps::class)
            ->set('activeTab', 'history')
            ->assertCanSeeTableRecords([$kuralHistory]);
    }

    public function test_employee_can_export_their_own_follow_ups_as_csv(): void
    {
        $employee = User::factory()->create();
        $this->makeFollowUp($employee, FollowUpStatus::Pending, 'Call back Tuesday');

        $this->actingAs($employee);

        $test = Livewire::test(ListFollowUps::class)
            ->callAction('exportCsv', data: ['scope' => 'pending']);

        $test->assertFileDownloaded();

        $content = base64_decode($test->effects['download']['content']);
        $this->assertStringContainsString('Call back Tuesday', $content);
    }

    public function test_employee_csv_export_cannot_contain_another_employees_follow_ups(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $this->makeFollowUp($owner, FollowUpStatus::Pending, 'Owners private reason');
        $this->makeFollowUp($intruder, FollowUpStatus::Pending, 'Intruders own reason');

        $this->actingAs($intruder);

        $test = Livewire::test(ListFollowUps::class)
            ->callAction('exportCsv', data: ['scope' => 'all']);

        $content = base64_decode($test->effects['download']['content']);
        $this->assertStringContainsString('Intruders own reason', $content);
        $this->assertStringNotContainsString('Owners private reason', $content);
    }

    public function test_admin_csv_export_includes_every_employees_follow_ups(): void
    {
        $admin = User::factory()->admin()->create();
        $nithin = User::factory()->create();
        $kural = User::factory()->create();
        $this->makeFollowUp($nithin, FollowUpStatus::Pending, 'Nithins reason');
        $this->makeFollowUp($kural, FollowUpStatus::Pending, 'Kurals reason');

        $this->actingAs($admin);

        $test = Livewire::test(ListFollowUps::class)
            ->callAction('exportCsv', data: ['scope' => 'all']);

        $content = base64_decode($test->effects['download']['content']);
        $this->assertStringContainsString('Nithins reason', $content);
        $this->assertStringContainsString('Kurals reason', $content);
    }

    public function test_csv_export_scope_filters_pending_vs_history(): void
    {
        $employee = User::factory()->create();
        $this->makeFollowUp($employee, FollowUpStatus::Pending, 'Still pending');
        $this->makeFollowUp($employee, FollowUpStatus::Completed, 'All done');

        $this->actingAs($employee);

        $pendingOnly = Livewire::test(ListFollowUps::class)
            ->callAction('exportCsv', data: ['scope' => 'pending']);
        $pendingContent = base64_decode($pendingOnly->effects['download']['content']);
        $this->assertStringContainsString('Still pending', $pendingContent);
        $this->assertStringNotContainsString('All done', $pendingContent);

        $historyOnly = Livewire::test(ListFollowUps::class)
            ->callAction('exportCsv', data: ['scope' => 'history']);
        $historyContent = base64_decode($historyOnly->effects['download']['content']);
        $this->assertStringContainsString('All done', $historyContent);
        $this->assertStringNotContainsString('Still pending', $historyContent);
    }

    public function test_leads_appointments_proposals_exports_remain_admin_only(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        Livewire::test(ListLeads::class)
            ->assertActionHidden('exportCsv');

        Livewire::test(ListAppointments::class)
            ->assertActionHidden('exportCsv');

        Livewire::test(ListProposals::class)
            ->assertActionHidden('exportCsv');
    }

    public function test_existing_follow_up_actions_still_function_alongside_tabs(): void
    {
        $employee = User::factory()->create();
        $followUp = $this->makeFollowUp($employee, FollowUpStatus::Pending);

        $this->actingAs($employee);

        Livewire::test(ListFollowUps::class)
            ->set('activeTab', 'pending')
            ->assertTableActionVisible('completed', $followUp)
            ->assertTableActionVisible('close', $followUp);
    }
}
