<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Filament\Widgets\KpiBand;
use App\Models\CallRecord;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * AGENTS.md sections 31, 33: the Employee Dashboard shows only that
 * employee's own numbers, while the Main Dashboard aggregates everyone's.
 * KpiBand (UX Fixes Batch Issue 6) replaced the old EmployeeStatsOverview/
 * OrgStatsOverview stat-card widgets but keeps the same employeeId-optional
 * scoping contract.
 */
class DashboardScopingTest extends TestCase
{
    use RefreshDatabase;

    private function callsTile(array $tiles): array
    {
        return collect($tiles)->firstWhere('label', 'Calls');
    }

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

        $tiles = Livewire::test(KpiBand::class, ['employeeId' => $nithin->id])
            ->instance()
            ->getTiles();

        $this->assertSame(2, $this->callsTile($tiles)['value']);
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

        $tiles = Livewire::test(KpiBand::class)
            ->instance()
            ->getTiles();

        $this->assertSame(2, $this->callsTile($tiles)['value']);
    }
}
