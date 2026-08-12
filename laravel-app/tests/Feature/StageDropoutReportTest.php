<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Filament\Pages\StageDropoutReport;
use App\Models\Lead;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Change Request "Decision 1": a simple aggregate dropout report per
 * pipeline (Lead/Appointment/Proposal) showing current-count-per-stage and
 * moved-past count, computed from each stage enum's declaration order.
 * Admin-only, same as the CSV exports.
 */
class StageDropoutReportTest extends TestCase
{
    use RefreshDatabase;

    private function makeLead(LeadStage $stage): Lead
    {
        $prospect = Prospect::factory()->create();

        return Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'stage' => $stage,
            'temperature' => 'warm',
        ]);
    }

    public function test_employee_cannot_access_the_report(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->get('/admin/stage-dropout-report')->assertForbidden();
    }

    public function test_admin_can_access_the_report(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/stage-dropout-report')->assertOk();
    }

    public function test_funnel_counts_current_and_moved_past_per_stage(): void
    {
        $admin = User::factory()->admin()->create();

        // 2 sitting at the first stage, 1 at the second, 3 at the last.
        $this->makeLead(LeadStage::RequirementCollection);
        $this->makeLead(LeadStage::RequirementCollection);
        $this->makeLead(LeadStage::DemoScheduledOrDone);
        $this->makeLead(LeadStage::Validated);
        $this->makeLead(LeadStage::Validated);
        $this->makeLead(LeadStage::Validated);

        $this->actingAs($admin);

        $funnels = (new StageDropoutReport)->getFunnels();
        $lead = collect($funnels['Lead'])->keyBy('label');

        $this->assertSame(2, $lead['Requirement Collection']['current']);
        $this->assertSame(4, $lead['Requirement Collection']['movedPast']);

        $this->assertSame(1, $lead['Demo Scheduled / Done']['current']);
        $this->assertSame(3, $lead['Demo Scheduled / Done']['movedPast']);

        $this->assertSame(3, $lead['Validated']['current']);
        $this->assertSame(0, $lead['Validated']['movedPast']);
    }
}
