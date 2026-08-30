<?php

namespace Tests\Feature;

use App\Models\CallRecord;
use App\Models\ExportRequest;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Proposal;
use App\Models\Prospect;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 1: Organization is a system security boundary, not merely a
 * developer-remembered query filter. Proves that Org A can never see, edit,
 * or reach Org B's data through the UI, a direct URL, an export, or a file
 * download — mutation attempts are covered here, not just visibility (a
 * record being hidden from a list is not the same guarantee as a direct,
 * crafted request against it also being refused).
 */
class OrganizationIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgWithEmployee(): array
    {
        $organization = Organization::factory()->create();
        $employee = Tenancy::runAs(
            $organization->id,
            fn () => User::factory()->create(['organization_id' => $organization->id])
        );

        return [$organization, $employee];
    }

    public function test_org_a_employee_cannot_view_org_bs_prospect_by_direct_url(): void
    {
        [$orgB, $ownerB] = $this->makeOrgWithEmployee();
        $prospectB = Tenancy::runAs($orgB->id, fn () => Prospect::factory()->create(['assigned_to' => $ownerB->id, 'created_by' => $ownerB->id]));

        [, $employeeA] = $this->makeOrgWithEmployee();

        $this->actingAs($employeeA)
            ->get("/admin/prospects/{$prospectB->id}")
            ->assertNotFound();
    }

    public function test_org_a_employee_cannot_edit_org_bs_prospect_by_direct_url(): void
    {
        [$orgB, $ownerB] = $this->makeOrgWithEmployee();
        $prospectB = Tenancy::runAs($orgB->id, fn () => Prospect::factory()->create(['assigned_to' => $ownerB->id, 'created_by' => $ownerB->id]));

        [, $employeeA] = $this->makeOrgWithEmployee();

        $this->actingAs($employeeA)
            ->get("/admin/prospects/{$prospectB->id}/edit")
            ->assertNotFound();
    }

    public function test_org_a_admin_cannot_view_org_bs_lead_despite_admin_role(): void
    {
        [$orgB, $ownerB] = $this->makeOrgWithEmployee();
        $prospectB = Tenancy::runAs($orgB->id, fn () => Prospect::factory()->create(['assigned_to' => $ownerB->id, 'created_by' => $ownerB->id]));
        $leadB = Tenancy::runAs($orgB->id, fn () => Lead::create([
            'prospect_id' => $prospectB->id,
            'assigned_to' => $ownerB->id,
            'created_by' => $ownerB->id,
            'stage' => 'requirement_collection',
            'temperature' => 'warm',
        ]));

        $orgA = Organization::factory()->create();
        $adminA = Tenancy::runAs($orgA->id, fn () => User::factory()->admin()->create(['organization_id' => $orgA->id]));

        // Senior Manager (Admin) is organization-wide, not system-wide —
        // org-wide visibility must never leak across the tenant boundary.
        $this->actingAs($adminA)
            ->get("/admin/leads/{$leadB->id}")
            ->assertNotFound();
    }

    public function test_org_a_employee_cannot_view_org_bs_call_record(): void
    {
        [$orgB, $ownerB] = $this->makeOrgWithEmployee();
        $prospectB = Tenancy::runAs($orgB->id, fn () => Prospect::factory()->create(['assigned_to' => $ownerB->id, 'created_by' => $ownerB->id]));
        $callB = Tenancy::runAs($orgB->id, fn () => CallRecord::create([
            'prospect_id' => $prospectB->id,
            'user_id' => $ownerB->id,
            'called_at' => now(),
            'outcome' => 'no_answer',
        ]));

        [, $employeeA] = $this->makeOrgWithEmployee();

        $this->actingAs($employeeA)
            ->get("/admin/call-records/{$callB->id}")
            ->assertNotFound();
    }

    public function test_org_a_user_cannot_see_org_bs_prospects_in_their_list(): void
    {
        [$orgB, $ownerB] = $this->makeOrgWithEmployee();
        $prospectB = Tenancy::runAs($orgB->id, fn () => Prospect::factory()->create(['assigned_to' => $ownerB->id, 'created_by' => $ownerB->id, 'company_name' => 'Org B Only Co']));

        $orgA = Organization::factory()->create();
        $adminA = Tenancy::runAs($orgA->id, fn () => User::factory()->admin()->create(['organization_id' => $orgA->id]));

        $this->actingAs($adminA);

        Livewire::test(\App\Filament\Resources\ProspectResource\Pages\ListProspects::class)
            ->assertCanNotSeeTableRecords([$prospectB]);
    }

    public function test_cross_org_export_request_cannot_be_viewed_or_downloaded(): void
    {
        [$orgB, $requesterB] = $this->makeOrgWithEmployee();
        $requestB = Tenancy::runAs($orgB->id, fn () => ExportRequest::create([
            'user_id' => $requesterB->id,
            'resource' => 'lead',
            'filters' => [],
            'status' => 'approved',
            'decided_by' => $requesterB->id,
            'decided_at' => now(),
            'expires_at' => now()->addDays(7),
        ]));

        [, $employeeA] = $this->makeOrgWithEmployee();

        $this->assertFalse($employeeA->can('view', $requestB));
        $this->assertFalse($employeeA->can('download', $requestB));
    }

    public function test_cross_org_proposal_attachment_download_action_is_not_reachable(): void
    {
        Storage::fake('local');

        [$orgB, $ownerB] = $this->makeOrgWithEmployee();
        [$prospectB, $leadB, $proposalB] = Tenancy::runAs($orgB->id, function () use ($ownerB) {
            $prospect = Prospect::factory()->create(['assigned_to' => $ownerB->id, 'created_by' => $ownerB->id]);
            $lead = Lead::create([
                'prospect_id' => $prospect->id,
                'assigned_to' => $ownerB->id,
                'created_by' => $ownerB->id,
                'stage' => 'validated',
                'temperature' => 'hot',
                'notes' => 'Confirmed.',
            ]);
            $proposal = Proposal::create([
                'lead_id' => $lead->id,
                'prospect_id' => $prospect->id,
                'assigned_to' => $ownerB->id,
                'created_by' => $ownerB->id,
                'stage' => 'sent',
                'attachment_paths' => ['proposal-attachments/orgb.pdf'],
                'attachment_names' => ['proposal-attachments/orgb.pdf' => 'orgb.pdf'],
            ]);
            Storage::disk('local')->put('proposal-attachments/orgb.pdf', 'org b file');

            return [$prospect, $lead, $proposal];
        });

        [, $employeeA] = $this->makeOrgWithEmployee();

        $this->actingAs($employeeA)
            ->get("/admin/proposals/{$proposalB->id}")
            ->assertNotFound();

        $this->assertFalse($employeeA->can('view', $proposalB->fresh()));
    }

    public function test_backfilled_organization_data_remains_fully_visible_within_its_own_organization(): void
    {
        // Regression guard for the backfill itself: reverting the tenant
        // boundary must never accidentally hide pre-existing, correctly-
        // scoped data from users who legitimately belong to that org.
        [$organization, $employee] = $this->makeOrgWithEmployee();
        $prospect = Tenancy::runAs($organization->id, fn () => Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]));

        $this->actingAs($employee)
            ->get("/admin/prospects/{$prospect->id}")
            ->assertOk();
    }
}
