<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Enums\ExportableResource;
use App\Enums\ExportRequestStatus;
use App\Filament\Resources\CallRecordResource\Pages\ListCallRecords;
use App\Models\CallRecord;
use App\Models\ExportRequest;
use App\Models\Prospect;
use App\Models\User;
use App\Support\Exports\CallRecordExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Call Records gets the same CSV export pattern already used for Leads/
 * Appointments/Proposals/Follow-Ups (Import Access + Export Approval batch):
 * admin gets an immediate download, an employee submits a Pending
 * ExportRequest via requestExport instead. Both share CallRecordExporter,
 * whose scopedQuery() is exactly CallRecordResource::getEloquentQuery()
 * (visibleTo($user)), so the privacy guarantee applies identically to
 * both paths.
 */
class CallRecordExportTest extends TestCase
{
    use RefreshDatabase;

    private function makeCallRecord(User $caller, CallOutcome $outcome, string $notes = 'Some notes'): CallRecord
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $caller->id, 'created_by' => $caller->id]);

        return CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $caller->id,
            'called_at' => now(),
            'outcome' => $outcome,
            'notes' => $notes,
        ]);
    }

    public function test_employee_cannot_see_the_immediate_export_action(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        Livewire::test(ListCallRecords::class)
            ->assertActionHidden('exportCsv')
            ->assertActionVisible('requestExport');
    }

    public function test_admin_sees_the_immediate_export_action_not_request(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(ListCallRecords::class)
            ->assertActionVisible('exportCsv')
            ->assertActionHidden('requestExport');
    }

    public function test_admin_can_export_immediately(): void
    {
        $admin = User::factory()->admin()->create();
        $this->makeCallRecord($admin, CallOutcome::RequirementIdentified, 'Admins own call');

        $this->actingAs($admin);

        $test = Livewire::test(ListCallRecords::class)->callAction('exportCsv', data: ['scope' => 'all']);
        $test->assertFileDownloaded();

        $content = base64_decode($test->effects['download']['content']);
        $this->assertStringContainsString('Admins own call', $content);
    }

    public function test_employee_can_request_export_of_their_own_call_records(): void
    {
        $employee = User::factory()->create();
        $this->makeCallRecord($employee, CallOutcome::RequirementIdentified);

        $this->actingAs($employee);

        Livewire::test(ListCallRecords::class)
            ->callAction('requestExport', data: ['scope' => 'all']);

        $this->assertDatabaseCount('export_requests', 1);
        $request = ExportRequest::first();
        $this->assertSame($employee->id, $request->user_id);
        $this->assertSame(ExportableResource::CallRecord, $request->resource);
        $this->assertSame(ExportRequestStatus::Pending, $request->status);
        $this->assertSame(['scope' => 'all'], $request->filters);
    }

    /**
     * The privacy guarantee lives in CallRecordExporter::scopedQuery() (via
     * visibleTo()), shared verbatim by the admin-immediate export and the
     * employee approved-request download — exercising it directly here
     * covers both paths without needing the download route.
     */
    public function test_employees_export_only_contains_their_own_call_records(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $this->makeCallRecord($owner, CallOutcome::RequirementIdentified, 'Owners private notes');
        $this->makeCallRecord($intruder, CallOutcome::RequirementIdentified, 'Intruders own notes');

        $response = (new CallRecordExporter)->stream($intruder, ['scope' => 'all']);

        $content = $this->streamedContent($response);
        $this->assertStringContainsString('Intruders own notes', $content);
        $this->assertStringNotContainsString('Owners private notes', $content);
    }

    public function test_admins_export_includes_every_employees_call_records(): void
    {
        $admin = User::factory()->admin()->create();
        $nithin = User::factory()->create();
        $kural = User::factory()->create();
        $this->makeCallRecord($nithin, CallOutcome::RequirementIdentified, 'Nithins notes');
        $this->makeCallRecord($kural, CallOutcome::RequirementIdentified, 'Kurals notes');

        $this->actingAs($admin);

        $test = Livewire::test(ListCallRecords::class)
            ->callAction('exportCsv', data: ['scope' => 'all']);

        $content = base64_decode($test->effects['download']['content']);
        $this->assertStringContainsString('Nithins notes', $content);
        $this->assertStringContainsString('Kurals notes', $content);
    }

    public function test_export_scope_filters_all_vs_history(): void
    {
        $employee = User::factory()->create();
        $this->makeCallRecord($employee, CallOutcome::RequirementIdentified, 'Routes to a lead');
        $this->makeCallRecord($employee, CallOutcome::Others, 'Routes nowhere');

        $exporter = new CallRecordExporter;

        $allContent = $this->streamedContent($exporter->stream($employee, ['scope' => 'all']));
        $this->assertStringContainsString('Routes to a lead', $allContent);
        $this->assertStringContainsString('Routes nowhere', $allContent);

        $historyContent = $this->streamedContent($exporter->stream($employee, ['scope' => 'history']));
        $this->assertStringContainsString('Routes nowhere', $historyContent);
        $this->assertStringNotContainsString('Routes to a lead', $historyContent);
    }

    /**
     * A Call Record generated by completing a Follow-Up is no longer hidden
     * anywhere (an earlier version of this feature excluded it here via
     * CallRecordExporter::scopedQuery()'s now-removed directlyLogged() call
     * — see FollowUpGeneratedCallRecordVisibilityTest for the full revert),
     * so the export includes it exactly like any directly-logged call.
     */
    public function test_export_includes_call_records_generated_by_a_completed_follow_up(): void
    {
        $employee = User::factory()->create();
        $directCall = $this->makeCallRecord($employee, CallOutcome::RequirementIdentified, 'Directly logged call');

        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $generatedCall = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $employee->id,
            'called_at' => now(),
            'outcome' => CallOutcome::RequirementIdentified,
            'notes' => 'Generated by follow-up completion',
            'follow_up_id' => \App\Models\FollowUp::create([
                'prospect_id' => $prospect->id,
                'user_id' => $employee->id,
                'follow_up_at' => now(),
                'reason' => 'Callback',
                'status' => \App\Enums\FollowUpStatus::Completed,
            ])->id,
        ]);

        $content = $this->streamedContent((new CallRecordExporter)->stream($employee, ['scope' => 'all']));

        $this->assertStringContainsString('Directly logged call', $content);
        $this->assertStringContainsString('Generated by follow-up completion', $content);
    }

    private function streamedContent(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return ob_get_clean();
    }
}
