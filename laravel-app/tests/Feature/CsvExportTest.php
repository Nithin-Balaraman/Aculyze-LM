<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Models\Lead;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Change Request "Decision 1": three separate stage-based CSV exports, each
 * with an "All" option. Only the Lead export is exercised in depth here —
 * the Appointment/Proposal exports (ListAppointments/ListProposals) share
 * the exact same CsvExporter + header-action pattern.
 */
class CsvExportTest extends TestCase
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

    public function test_employee_cannot_see_the_export_action(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee);

        Livewire::test(ListLeads::class)->assertActionHidden('exportCsv');
    }

    public function test_export_with_no_stage_filter_includes_every_lead(): void
    {
        $admin = User::factory()->admin()->create();
        $this->makeLead(LeadStage::RequirementCollection);
        $this->makeLead(LeadStage::Validated);

        $this->actingAs($admin);

        $test = Livewire::test(ListLeads::class)->callAction('exportCsv', data: ['stage' => null]);
        $test->assertFileDownloaded();

        $content = base64_decode($test->effects['download']['content']);
        $this->assertSame(3, substr_count($content, "\n")); // header + 2 rows
        $this->assertStringContainsString('Requirement Collection', $content);
        $this->assertStringContainsString('Validated', $content);
    }

    public function test_export_filtered_by_stage_only_includes_that_stage(): void
    {
        $admin = User::factory()->admin()->create();
        $this->makeLead(LeadStage::RequirementCollection);
        $this->makeLead(LeadStage::Validated);

        $this->actingAs($admin);

        $test = Livewire::test(ListLeads::class)
            ->callAction('exportCsv', data: ['stage' => LeadStage::Validated->value]);
        $test->assertFileDownloaded();

        $content = base64_decode($test->effects['download']['content']);
        $this->assertSame(2, substr_count($content, "\n")); // header + 1 row
        $this->assertStringContainsString('Validated', $content);
        $this->assertStringNotContainsString('Requirement Collection', $content);
    }

    public function test_export_includes_lost_designation(): void
    {
        $admin = User::factory()->admin()->create();
        $lead = $this->makeLead(LeadStage::DemoScheduledOrDone);
        $lead->markLost('Budget cut.');

        $this->actingAs($admin);

        $test = Livewire::test(ListLeads::class)->callAction('exportCsv', data: ['stage' => null]);

        $content = base64_decode($test->effects['download']['content']);
        $this->assertStringContainsString('Budget cut.', $content);
    }
}
