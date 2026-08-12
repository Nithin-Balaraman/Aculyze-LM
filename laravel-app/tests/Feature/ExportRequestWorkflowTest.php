<?php

namespace Tests\Feature;

use App\Enums\ExportableResource;
use App\Enums\ExportRequestStatus;
use App\Enums\LeadStage;
use App\Enums\ProposalStage;
use App\Filament\Resources\AppointmentResource\Pages\ListAppointments;
use App\Filament\Resources\FollowUpResource\Pages\ListFollowUps;
use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Filament\Resources\ProposalResource\Pages\ListProposals;
use App\Models\ExportRequest;
use App\Models\Lead;
use App\Models\Prospect;
use App\Models\User;
use App\Support\Exports\LeadExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Import Access + Export Approval batch, Section 2: end-to-end coverage of
 * the "Request Export" flow across all four resources, plus the
 * duplicate-request rules from Pre-Answered Question 3 (Pending always
 * deduplicated; an existing Approved-and-unexpired request also blocks a
 * new one; Denied/Expired never block).
 */
class ExportRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_request_a_lead_export(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        Livewire::test(ListLeads::class)->callAction('requestExport', data: ['stage' => LeadStage::Validated->value]);

        $this->assertDatabaseCount('export_requests', 1);
        $request = ExportRequest::first();
        $this->assertSame($employee->id, $request->user_id);
        $this->assertSame(ExportableResource::Lead, $request->resource);
        $this->assertSame(['stage' => LeadStage::Validated->value], $request->filters);
        $this->assertSame(ExportRequestStatus::Pending, $request->status);
    }

    public function test_employee_can_request_an_appointment_export(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        Livewire::test(ListAppointments::class)->callAction('requestExport', data: ['stage' => null]);

        $request = ExportRequest::first();
        $this->assertSame(ExportableResource::Appointment, $request->resource);
        $this->assertSame(['stage' => null], $request->filters);
    }

    public function test_employee_can_request_a_proposal_export(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        Livewire::test(ListProposals::class)->callAction('requestExport', data: ['stage' => ProposalStage::Sent->value]);

        $request = ExportRequest::first();
        $this->assertSame(ExportableResource::Proposal, $request->resource);
        $this->assertSame(['stage' => ProposalStage::Sent->value], $request->filters);
    }

    public function test_employee_can_request_a_follow_up_export(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        Livewire::test(ListFollowUps::class)->callAction('requestExport', data: ['scope' => 'history']);

        $request = ExportRequest::first();
        $this->assertSame(ExportableResource::FollowUp, $request->resource);
        $this->assertSame(['scope' => 'history'], $request->filters);
    }

    public function test_identical_pending_request_is_deduplicated(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        Livewire::test(ListLeads::class)->callAction('requestExport', data: ['stage' => null]);
        Livewire::test(ListLeads::class)->callAction('requestExport', data: ['stage' => null]);

        $this->assertDatabaseCount('export_requests', 1);
    }

    public function test_pending_requests_with_different_filters_are_not_deduplicated(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        Livewire::test(ListLeads::class)->callAction('requestExport', data: ['stage' => null]);
        Livewire::test(ListLeads::class)->callAction('requestExport', data: ['stage' => LeadStage::Validated->value]);

        $this->assertDatabaseCount('export_requests', 2);
    }

    public function test_a_different_employees_pending_request_does_not_deduplicate_against_this_employees(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();
        ExportRequest::create([
            'user_id' => $other->id,
            'resource' => ExportableResource::Lead,
            'filters' => ['stage' => null],
            'status' => ExportRequestStatus::Pending,
        ]);

        $this->actingAs($employee);
        Livewire::test(ListLeads::class)->callAction('requestExport', data: ['stage' => null]);

        $this->assertDatabaseCount('export_requests', 2);
    }

    public function test_existing_approved_and_unexpired_request_blocks_a_duplicate_new_request(): void
    {
        $employee = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $existing = ExportRequest::create([
            'user_id' => $employee->id,
            'resource' => ExportableResource::Lead,
            'filters' => ['stage' => null],
            'status' => ExportRequestStatus::Pending,
        ]);
        $existing->approve($admin);

        $this->actingAs($employee);
        Livewire::test(ListLeads::class)->callAction('requestExport', data: ['stage' => null]);

        $this->assertDatabaseCount('export_requests', 1);
    }

    public function test_denied_request_does_not_block_a_new_request(): void
    {
        $employee = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $existing = ExportRequest::create([
            'user_id' => $employee->id,
            'resource' => ExportableResource::Lead,
            'filters' => ['stage' => null],
            'status' => ExportRequestStatus::Pending,
        ]);
        $existing->deny($admin, null);

        $this->actingAs($employee);
        Livewire::test(ListLeads::class)->callAction('requestExport', data: ['stage' => null]);

        $this->assertDatabaseCount('export_requests', 2);
        $this->assertSame(ExportRequestStatus::Pending, ExportRequest::latest('id')->first()->status);
    }

    public function test_expired_approved_request_does_not_block_a_new_request(): void
    {
        $employee = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $existing = ExportRequest::create([
            'user_id' => $employee->id,
            'resource' => ExportableResource::Lead,
            'filters' => ['stage' => null],
            'status' => ExportRequestStatus::Pending,
        ]);
        $existing->approve($admin);
        $existing->forceFill(['expires_at' => now()->subDay()])->save();

        $this->actingAs($employee);
        Livewire::test(ListLeads::class)->callAction('requestExport', data: ['stage' => null]);

        $this->assertDatabaseCount('export_requests', 2);
    }

    public function test_requested_lead_stage_filter_is_applied_correctly_through_the_full_pipeline(): void
    {
        $employee = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->makeLead($employee, LeadStage::Validated, 'Validated Co');
        $this->makeLead($employee, LeadStage::RequirementCollection, 'Requirement Co');

        $request = ExportRequest::create([
            'user_id' => $employee->id,
            'resource' => ExportableResource::Lead,
            'filters' => ['stage' => LeadStage::Validated->value],
            'status' => ExportRequestStatus::Pending,
        ]);
        $request->approve($admin);

        $response = (new LeadExporter)->stream($employee, $request->filters);

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        $this->assertStringContainsString('Validated Co', $content);
        $this->assertStringNotContainsString('Requirement Co', $content);
    }

    public function test_employee_cannot_download_a_pending_or_denied_request(): void
    {
        $employee = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $pending = ExportRequest::create([
            'user_id' => $employee->id,
            'resource' => ExportableResource::Lead,
            'filters' => ['stage' => null],
            'status' => ExportRequestStatus::Pending,
        ]);
        $this->assertFalse($employee->can('download', $pending));

        $denied = ExportRequest::create([
            'user_id' => $employee->id,
            'resource' => ExportableResource::Appointment,
            'filters' => ['stage' => null],
            'status' => ExportRequestStatus::Pending,
        ]);
        $denied->deny($admin, null);
        $this->assertFalse($employee->can('download', $denied));
    }

    private function makeLead(User $owner, LeadStage $stage, string $company): Lead
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id, 'company_name' => $company]);

        return Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'stage' => $stage,
            'temperature' => 'warm',
        ]);
    }
}
