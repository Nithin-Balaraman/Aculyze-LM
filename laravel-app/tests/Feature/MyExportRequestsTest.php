<?php

namespace Tests\Feature;

use App\Enums\ExportableResource;
use App\Enums\ExportRequestStatus;
use App\Enums\LeadStage;
use App\Enums\LeadTemperature;
use App\Filament\Pages\MyExportRequests;
use App\Models\ExportRequest;
use App\Models\Lead;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Import Access + Export Approval batch, Section 2.15-2.18: the employee's
 * own export-request history and the secure Approved-only download. The
 * page must never show another employee's requests (Section 2.15 — "never
 * org-wide"), and the CSV must only ever be generated for a Downloadable
 * (Approved, not expired) request that belongs to the downloading user.
 */
class MyExportRequestsTest extends TestCase
{
    use RefreshDatabase;

    private function makeLeadRequest(User $employee, ExportRequestStatus $status = ExportRequestStatus::Pending, array $filters = ['stage' => null]): ExportRequest
    {
        return ExportRequest::create([
            'user_id' => $employee->id,
            'resource' => ExportableResource::Lead,
            'filters' => $filters,
            'status' => $status,
        ]);
    }

    private function makeLead(User $owner, string $company): Lead
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id, 'company_name' => $company]);

        return Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'stage' => LeadStage::cases()[0],
            'temperature' => LeadTemperature::Warm,
        ]);
    }

    public function test_employee_only_sees_their_own_export_requests(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ownRequest = $this->makeLeadRequest($owner);
        $otherRequest = $this->makeLeadRequest($other);

        $this->actingAs($owner);

        Livewire::test(MyExportRequests::class)
            ->assertCanSeeTableRecords([$ownRequest])
            ->assertCanNotSeeTableRecords([$otherRequest]);
    }

    public function test_pending_request_has_no_download_action(): void
    {
        $employee = User::factory()->create();
        $request = $this->makeLeadRequest($employee, ExportRequestStatus::Pending);

        $this->actingAs($employee);

        Livewire::test(MyExportRequests::class)
            ->assertTableActionHidden('download', $request);
    }

    public function test_denied_request_has_no_download_action(): void
    {
        $employee = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $request = $this->makeLeadRequest($employee, ExportRequestStatus::Pending);
        $request->deny($admin, 'Not authorized for bulk export.');

        $this->actingAs($employee);

        Livewire::test(MyExportRequests::class)
            ->assertTableActionHidden('download', $request);
    }

    public function test_expired_approved_request_has_no_download_action(): void
    {
        $employee = User::factory()->create();
        $request = $this->makeLeadRequest($employee, ExportRequestStatus::Approved);
        $request->forceFill(['expires_at' => now()->subDay()])->save();

        $this->actingAs($employee);

        Livewire::test(MyExportRequests::class)
            ->assertTableActionHidden('download', $request);

        $this->assertSame('Expired', $request->effectiveStatusLabel());
    }

    public function test_approved_unexpired_request_can_be_downloaded_and_contains_only_that_employees_rows(): void
    {
        $employee = User::factory()->create();
        $intruderData = User::factory()->create();
        $this->makeLead($employee, 'Employee Owned Co');
        $this->makeLead($intruderData, 'Someone Elses Co');

        $request = $this->makeLeadRequest($employee, ExportRequestStatus::Approved, ['stage' => null]);
        $request->forceFill(['expires_at' => now()->addDays(7)])->save();

        $this->actingAs($employee);

        $test = Livewire::test(MyExportRequests::class)
            ->callTableAction('download', $request);

        $test->assertFileDownloaded();
        $content = base64_decode($test->effects['download']['content']);
        $this->assertStringContainsString('Employee Owned Co', $content);
        $this->assertStringNotContainsString('Someone Elses Co', $content);

        $this->assertNotNull($request->fresh()->downloaded_at);
        $this->assertSame(ExportRequestStatus::Approved, $request->fresh()->status);
    }

    public function test_employee_cannot_download_another_employees_approved_request(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $request = $this->makeLeadRequest($owner, ExportRequestStatus::Approved);
        $request->forceFill(['expires_at' => now()->addDays(7)])->save();

        $this->assertFalse($intruder->can('download', $request));

        $this->actingAs($intruder);

        // Not visible in the intruder's own list at all (scoped query).
        Livewire::test(MyExportRequests::class)
            ->assertCanNotSeeTableRecords([$request]);
    }

    public function test_downloading_does_not_change_status(): void
    {
        $employee = User::factory()->create();
        $request = $this->makeLeadRequest($employee, ExportRequestStatus::Approved, ['stage' => null]);
        $request->forceFill(['expires_at' => now()->addDays(7)])->save();

        $this->actingAs($employee);

        Livewire::test(MyExportRequests::class)
            ->callTableAction('download', $request);

        $this->assertSame(ExportRequestStatus::Approved, $request->fresh()->status);
    }
}
