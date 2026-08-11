<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Filament\Widgets\EmployeeStatsOverview;
use App\Filament\Widgets\OrgStatsOverview;
use App\Models\CallRecord;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * AGENTS.md sections 31, 33: the Employee Dashboard shows only that
 * employee's own numbers, while the Main Dashboard aggregates everyone's.
 */
class DashboardScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_dashboard_only_counts_that_employees_calls(): void
    {
        $nithin = User::factory()->create();
        $kural = User::factory()->create();

        $nithinProspect = Prospect::factory()->create(['assigned_to' => $nithin->id, 'created_by' => $nithin->id]);
        $kuralProspect = Prospect::factory()->create(['assigned_to' => $kural->id, 'created_by' => $kural->id]);

        CallRecord::create(['prospect_id' => $nithinProspect->id, 'user_id' => $nithin->id, 'called_at' => now(), 'outcome' => CallOutcome::NoAnswer]);
        CallRecord::create(['prospect_id' => $nithinProspect->id, 'user_id' => $nithin->id, 'called_at' => now(), 'outcome' => CallOutcome::NoAnswer]);
        CallRecord::create(['prospect_id' => $kuralProspect->id, 'user_id' => $kural->id, 'called_at' => now(), 'outcome' => CallOutcome::NoAnswer]);

        $this->actingAs($nithin);

        Livewire::test(EmployeeStatsOverview::class, ['employeeId' => $nithin->id])
            ->assertSee('Calls Made')
            ->assertSeeHtml('2');
    }

    public function test_main_dashboard_aggregates_calls_across_every_employee(): void
    {
        $admin = User::factory()->admin()->create();
        $nithin = User::factory()->create();
        $kural = User::factory()->create();

        $nithinProspect = Prospect::factory()->create(['assigned_to' => $nithin->id, 'created_by' => $nithin->id]);
        $kuralProspect = Prospect::factory()->create(['assigned_to' => $kural->id, 'created_by' => $kural->id]);

        CallRecord::create(['prospect_id' => $nithinProspect->id, 'user_id' => $nithin->id, 'called_at' => now(), 'outcome' => CallOutcome::NoAnswer]);
        CallRecord::create(['prospect_id' => $kuralProspect->id, 'user_id' => $kural->id, 'called_at' => now(), 'outcome' => CallOutcome::NoAnswer]);

        $this->actingAs($admin);

        Livewire::test(OrgStatsOverview::class)
            ->assertSee('Total Calls')
            ->assertSeeHtml('2');
    }
}
