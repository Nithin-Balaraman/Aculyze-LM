<?php

namespace Tests\Feature;

use App\Enums\DemoMode;
use App\Enums\DemoStatus;
use App\Enums\LeadStage;
use App\Filament\Widgets\ProspectDemosTable;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Demo is one of six activity tabs on the Prospect View page (see
 * ViewProspect::infolist() and ProspectDemosTable) — added because it
 * was missing from the original five. Demo carries its own
 * prospect_id (confirmed against the demos table migration, not assumed
 * from its also-present lead_id), so the count here is a flat count of
 * every Demo belonging to this Prospect directly, regardless of which of
 * the Prospect's Leads each Demo happens to be under — this is the
 * intended behavior: a Prospect with 2 Leads that each have Demos should
 * show the combined total, the same way ProspectProposalsTable already
 * counts every Proposal for the company regardless of which Lead
 * produced it.
 */
class ProspectDemoSummaryCountTest extends TestCase
{
    use RefreshDatabase;

    private function createDemo(Prospect $prospect, Lead $lead, User $employee): Demo
    {
        return Demo::create([
            'prospect_id' => $prospect->id,
            'lead_id' => $lead->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'demo_at' => now()->addDay(),
            'mode' => DemoMode::Online,
            'meeting_link' => 'https://meet.example.com/demo',
            'status' => DemoStatus::Scheduled,
        ]);
    }

    public function test_shows_zero_demos_not_blank_or_hidden(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);

        $test = Livewire::test(ProspectDemosTable::class, ['record' => $prospect, 'filters' => []]);

        $test->assertCanSeeTableRecords([]);
        $test->assertSee('No demos for this company yet.');
        $this->assertSame(0, $test->instance()->getTable()->getQuery()->count());
    }

    public function test_shows_exactly_one_demo(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $lead = Lead::create(['prospect_id' => $prospect->id, 'assigned_to' => $employee->id, 'created_by' => $employee->id, 'stage' => LeadStage::RequirementCollection, 'temperature' => 'warm']);
        $demo = $this->createDemo($prospect, $lead, $employee);

        Livewire::test(ProspectDemosTable::class, ['record' => $prospect, 'filters' => []])
            ->assertCanSeeTableRecords([$demo])
            ->assertCountTableRecords(1);
    }

    /**
     * The scenario the task explicitly asked to confirm: 2 Leads under
     * the same Prospect, each with their own Demo(s) — the total must
     * reflect every one of them combined, not just one Lead's.
     */
    public function test_counts_demos_across_multiple_leads_on_the_same_prospect(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);

        $leadOne = Lead::create(['prospect_id' => $prospect->id, 'assigned_to' => $employee->id, 'created_by' => $employee->id, 'stage' => LeadStage::RequirementCollection, 'temperature' => 'warm']);
        $leadTwo = Lead::create(['prospect_id' => $prospect->id, 'assigned_to' => $employee->id, 'created_by' => $employee->id, 'stage' => LeadStage::RequirementCollection, 'temperature' => 'warm']);

        $demoOne = $this->createDemo($prospect, $leadOne, $employee);
        $demoTwo = $this->createDemo($prospect, $leadOne, $employee);
        $demoThree = $this->createDemo($prospect, $leadTwo, $employee);

        Livewire::test(ProspectDemosTable::class, ['record' => $prospect, 'filters' => []])
            ->assertCanSeeTableRecords([$demoOne, $demoTwo, $demoThree])
            ->assertCountTableRecords(3);
    }

    /**
     * A Demo under a DIFFERENT Prospect's Lead must never be counted here
     * — proves the count is scoped by this Prospect's own prospect_id,
     * not accidentally by some other, looser join through Lead.
     */
    public function test_does_not_count_demos_belonging_to_a_different_prospect(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);
        $thisProspect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $otherProspect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);

        $thisLead = Lead::create(['prospect_id' => $thisProspect->id, 'assigned_to' => $employee->id, 'created_by' => $employee->id, 'stage' => LeadStage::RequirementCollection, 'temperature' => 'warm']);
        $otherLead = Lead::create(['prospect_id' => $otherProspect->id, 'assigned_to' => $employee->id, 'created_by' => $employee->id, 'stage' => LeadStage::RequirementCollection, 'temperature' => 'warm']);

        $thisDemo = $this->createDemo($thisProspect, $thisLead, $employee);
        $otherDemo = $this->createDemo($otherProspect, $otherLead, $employee);

        Livewire::test(ProspectDemosTable::class, ['record' => $thisProspect, 'filters' => []])
            ->assertCanSeeTableRecords([$thisDemo])
            ->assertCanNotSeeTableRecords([$otherDemo])
            ->assertCountTableRecords(1);
    }
}
