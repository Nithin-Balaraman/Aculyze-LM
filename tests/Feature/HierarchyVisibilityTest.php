<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 1 hierarchy: Employee sees only their own; Manager sees their own
 * + their direct reports'; Senior Manager sees everyone in their own
 * organization (never across organizations — see OrganizationIsolationTest
 * for that boundary specifically). All fixtures here live in one
 * organization, so only the hierarchy dimension is being exercised.
 */
class HierarchyVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function seniorManager(Organization $organization): User
    {
        return User::factory()->create(['organization_id' => $organization->id, 'role' => UserRole::Admin]);
    }

    private function manager(Organization $organization, User $seniorManager): User
    {
        $manager = User::factory()->create(['organization_id' => $organization->id, 'role' => UserRole::Manager]);
        $manager->update(['manager_id' => $seniorManager->id]);

        return $manager->fresh();
    }

    private function employeeReportingTo(Organization $organization, User $manager): User
    {
        $employee = User::factory()->create(['organization_id' => $organization->id, 'role' => UserRole::Employee]);
        $employee->update(['manager_id' => $manager->id]);

        return $employee->fresh();
    }

    public function test_employee_sees_only_their_own_prospects(): void
    {
        $organization = Organization::factory()->create();
        $senior = $this->seniorManager($organization);
        $manager = $this->manager($organization, $senior);
        $employee = $this->employeeReportingTo($organization, $manager);
        $colleague = $this->employeeReportingTo($organization, $manager);

        $own = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $colleagues = Prospect::factory()->create(['assigned_to' => $colleague->id, 'created_by' => $colleague->id]);

        $this->actingAs($employee);

        Livewire::test(\App\Filament\Resources\ProspectResource\Pages\ListProspects::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$colleagues]);
    }

    public function test_manager_sees_own_and_direct_reports_prospects_but_not_other_teams(): void
    {
        $organization = Organization::factory()->create();
        $senior = $this->seniorManager($organization);
        $manager = $this->manager($organization, $senior);
        $report = $this->employeeReportingTo($organization, $manager);

        $otherManager = $this->manager($organization, $senior);
        $otherReport = $this->employeeReportingTo($organization, $otherManager);

        $managerOwn = Prospect::factory()->create(['assigned_to' => $manager->id, 'created_by' => $manager->id]);
        $reportsProspect = Prospect::factory()->create(['assigned_to' => $report->id, 'created_by' => $report->id]);
        $otherTeamsProspect = Prospect::factory()->create(['assigned_to' => $otherReport->id, 'created_by' => $otherReport->id]);

        $this->actingAs($manager);

        Livewire::test(\App\Filament\Resources\ProspectResource\Pages\ListProspects::class)
            ->assertCanSeeTableRecords([$managerOwn, $reportsProspect])
            ->assertCanNotSeeTableRecords([$otherTeamsProspect]);
    }

    public function test_senior_manager_sees_everyone_in_the_organization(): void
    {
        $organization = Organization::factory()->create();
        $senior = $this->seniorManager($organization);
        $manager = $this->manager($organization, $senior);
        $report = $this->employeeReportingTo($organization, $manager);

        $reportsProspect = Prospect::factory()->create(['assigned_to' => $report->id, 'created_by' => $report->id]);
        $managersProspect = Prospect::factory()->create(['assigned_to' => $manager->id, 'created_by' => $manager->id]);

        $this->actingAs($senior);

        Livewire::test(\App\Filament\Resources\ProspectResource\Pages\ListProspects::class)
            ->assertCanSeeTableRecords([$reportsProspect, $managersProspect]);
    }

    public function test_manager_can_view_a_direct_reports_lead_but_not_another_teams(): void
    {
        $organization = Organization::factory()->create();
        $senior = $this->seniorManager($organization);
        $manager = $this->manager($organization, $senior);
        $report = $this->employeeReportingTo($organization, $manager);

        $otherManager = $this->manager($organization, $senior);
        $otherReport = $this->employeeReportingTo($organization, $otherManager);

        $reportsLead = Tenancy::runAs($organization->id, function () use ($report) {
            $prospect = Prospect::factory()->create(['assigned_to' => $report->id, 'created_by' => $report->id]);

            return Lead::create([
                'prospect_id' => $prospect->id,
                'assigned_to' => $report->id,
                'created_by' => $report->id,
                'stage' => 'requirement_collection',
                'temperature' => 'warm',
            ]);
        });

        $otherTeamsLead = Tenancy::runAs($organization->id, function () use ($otherReport) {
            $prospect = Prospect::factory()->create(['assigned_to' => $otherReport->id, 'created_by' => $otherReport->id]);

            return Lead::create([
                'prospect_id' => $prospect->id,
                'assigned_to' => $otherReport->id,
                'created_by' => $otherReport->id,
                'stage' => 'requirement_collection',
                'temperature' => 'warm',
            ]);
        });

        $this->actingAs($manager);

        $this->get("/admin/leads/{$reportsLead->id}")->assertOk();
        $this->get("/admin/leads/{$otherTeamsLead->id}")->assertNotFound();
    }

    public function test_manager_can_approve_a_direct_reports_export_request_but_not_another_teams(): void
    {
        $organization = Organization::factory()->create();
        $senior = $this->seniorManager($organization);
        $manager = $this->manager($organization, $senior);
        $report = $this->employeeReportingTo($organization, $manager);

        $otherManager = $this->manager($organization, $senior);
        $otherReport = $this->employeeReportingTo($organization, $otherManager);

        $reportsRequest = Tenancy::runAs($organization->id, fn () => \App\Models\ExportRequest::create([
            'user_id' => $report->id,
            'resource' => 'lead',
            'filters' => [],
            'status' => 'pending',
        ]));

        $otherTeamsRequest = Tenancy::runAs($organization->id, fn () => \App\Models\ExportRequest::create([
            'user_id' => $otherReport->id,
            'resource' => 'lead',
            'filters' => [],
            'status' => 'pending',
        ]));

        $this->assertTrue($manager->can('decide', $reportsRequest));
        $this->assertFalse($manager->can('decide', $otherTeamsRequest));
    }
}
