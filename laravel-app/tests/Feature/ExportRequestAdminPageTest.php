<?php

namespace Tests\Feature;

use App\Enums\ExportableResource;
use App\Enums\ExportRequestStatus;
use App\Filament\Resources\ExportRequestResource;
use App\Filament\Resources\ExportRequestResource\Pages\ListExportRequests;
use App\Models\ExportRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Import Access + Export Approval batch, Section 2.13/2.14: the admin-only
 * Export Requests page — every employee-initiated export waits here until
 * an admin approves or denies it. This IS the audit trail (Section 2.24),
 * so what the table shows and what the Approve/Deny actions record is the
 * whole feature's paper trail.
 */
class ExportRequestAdminPageTest extends TestCase
{
    use RefreshDatabase;

    private function makeRequest(User $employee, ExportableResource $resource = ExportableResource::Lead, array $filters = ['stage' => null]): ExportRequest
    {
        return ExportRequest::create([
            'user_id' => $employee->id,
            'resource' => $resource,
            'filters' => $filters,
            'status' => ExportRequestStatus::Pending,
        ]);
    }

    public function test_employee_cannot_access_the_export_requests_page(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        $this->assertFalse(ExportRequestResource::canAccess());
    }

    public function test_admin_can_access_the_export_requests_page_and_sees_every_employees_requests(): void
    {
        $admin = User::factory()->admin()->create();
        $nithin = User::factory()->create();
        $kural = User::factory()->create();
        $nithinRequest = $this->makeRequest($nithin);
        $kuralRequest = $this->makeRequest($kural, ExportableResource::FollowUp, ['scope' => 'pending']);

        $this->actingAs($admin);

        Livewire::test(ListExportRequests::class)
            ->assertCanSeeTableRecords([$nithinRequest, $kuralRequest]);
    }

    public function test_admin_can_approve_a_pending_request(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create();
        $request = $this->makeRequest($employee);

        $this->actingAs($admin);

        Livewire::test(ListExportRequests::class)
            ->callTableAction('approve', $request);

        $request->refresh();
        $this->assertSame(ExportRequestStatus::Approved, $request->status);
        $this->assertSame($admin->id, $request->decided_by);
        $this->assertNotNull($request->decided_at);
        $this->assertNotNull($request->expires_at);
        $this->assertEqualsWithDelta(
            now()->addDays((int) config('aculyze.export_request_validity_days'))->timestamp,
            $request->expires_at->timestamp,
            5
        );
    }

    public function test_admin_can_deny_a_pending_request_with_an_optional_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create();
        $request = $this->makeRequest($employee);

        $this->actingAs($admin);

        Livewire::test(ListExportRequests::class)
            ->callTableAction('deny', $request, data: ['reason' => 'Not needed right now.']);

        $request->refresh();
        $this->assertSame(ExportRequestStatus::Denied, $request->status);
        $this->assertSame($admin->id, $request->decided_by);
        $this->assertNotNull($request->decided_at);
        $this->assertSame('Not needed right now.', $request->denial_reason);
    }

    public function test_denial_reason_is_optional(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create();
        $request = $this->makeRequest($employee);

        $this->actingAs($admin);

        Livewire::test(ListExportRequests::class)
            ->callTableAction('deny', $request, data: ['reason' => null]);

        $this->assertSame(ExportRequestStatus::Denied, $request->fresh()->status);
        $this->assertNull($request->fresh()->denial_reason);
    }

    public function test_a_decided_request_cannot_be_decided_again(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create();
        $request = $this->makeRequest($employee);
        $request->approve($admin);

        $this->actingAs($admin);

        Livewire::test(ListExportRequests::class)
            ->assertTableActionHidden('approve', $request)
            ->assertTableActionHidden('deny', $request);

        $this->assertSame(ExportRequestStatus::Approved, $request->fresh()->status);
    }

    public function test_employee_cannot_approve_or_deny_via_the_policy(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();
        $request = $this->makeRequest($other);

        $this->assertFalse($employee->can('decide', $request));
    }

    public function test_admin_can_approve_or_deny_via_the_policy(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create();
        $request = $this->makeRequest($employee);

        $this->assertTrue($admin->can('decide', $request));
    }

    public function test_navigation_badge_reflects_pending_count_only(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create();
        $this->actingAs($admin);

        $this->assertNull(ExportRequestResource::getNavigationBadge());

        $pending = $this->makeRequest($employee);
        $this->assertSame('1', ExportRequestResource::getNavigationBadge());

        $pending->approve($admin);
        $this->assertNull(ExportRequestResource::getNavigationBadge());
    }
}
