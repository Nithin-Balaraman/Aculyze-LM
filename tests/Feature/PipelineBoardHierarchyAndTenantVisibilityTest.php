<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\PipelineBoard;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pipeline Board redesign, Section 17 items 6-9: every lane already reuses
 * each Resource's own `getEloquentQuery()` (already `->visibleTo()`-scoped)
 * or `Model::query()->visibleTo()` directly (see PipelineBoard::
 * callLane()/followUpLane()/appointmentLane()/leadLane()/demoLane()/
 * proposalLane()) — hierarchy and tenant isolation are inherited for free,
 * identically to every other list page in this app, with zero board-
 * specific authorization logic. No prior test exercised PipelineBoard::
 * getLanes() itself across roles/organizations — HierarchyVisibility and
 * OrganizationScope are already covered exhaustively elsewhere
 * (AuthorizationTest.php, TenancyBypassUsageTest.php, etc.), so this file
 * only confirms the BOARD's own aggregate output correctly inherits both,
 * rather than re-testing the underlying scopes themselves.
 */
class PipelineBoardHierarchyAndTenantVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function leadIdsOnBoard(): array
    {
        $lanes = app(PipelineBoard::class)->getLanes();
        $ids = [];

        foreach ($lanes['lead']['stages'] as $stage) {
            foreach ($stage['cards'] as $card) {
                $ids[] = $card['id'];
            }
        }

        return $ids;
    }

    public function test_an_employee_sees_only_their_own_leads_on_the_board(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $seniorManager = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Admin]);
            $manager = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Manager, 'manager_id' => $seniorManager->id]);
            $employeeA = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Employee, 'manager_id' => $manager->id]);
            $employeeB = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Employee, 'manager_id' => $manager->id]);

            $prospectA = Prospect::factory()->create(['assigned_to' => $employeeA->id, 'created_by' => $employeeA->id]);
            $prospectB = Prospect::factory()->create(['assigned_to' => $employeeB->id, 'created_by' => $employeeB->id]);

            $leadA = Lead::create([
                'prospect_id' => $prospectA->id, 'assigned_to' => $employeeA->id, 'created_by' => $employeeA->id,
                'stage' => 'requirement_collection', 'temperature' => 'hot', 'notes' => 'x',
            ]);
            $leadB = Lead::create([
                'prospect_id' => $prospectB->id, 'assigned_to' => $employeeB->id, 'created_by' => $employeeB->id,
                'stage' => 'requirement_collection', 'temperature' => 'hot', 'notes' => 'x',
            ]);

            $this->actingAs($employeeA);
            $ids = $this->leadIdsOnBoard();

            $this->assertContains($leadA->id, $ids);
            $this->assertNotContains($leadB->id, $ids, "An Employee's own board must never show a peer's Lead.");
        });
    }

    public function test_a_manager_sees_their_own_and_their_direct_reports_leads_but_not_an_unrelated_employees(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $seniorManager = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Admin]);
            $manager = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Manager, 'manager_id' => $seniorManager->id]);
            $otherManager = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Manager, 'manager_id' => $seniorManager->id]);
            $reportee = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Employee, 'manager_id' => $manager->id]);
            $unrelatedEmployee = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Employee, 'manager_id' => $otherManager->id]);

            $prospectReportee = Prospect::factory()->create(['assigned_to' => $reportee->id, 'created_by' => $reportee->id]);
            $prospectUnrelated = Prospect::factory()->create(['assigned_to' => $unrelatedEmployee->id, 'created_by' => $unrelatedEmployee->id]);

            $leadReportee = Lead::create([
                'prospect_id' => $prospectReportee->id, 'assigned_to' => $reportee->id, 'created_by' => $reportee->id,
                'stage' => 'requirement_collection', 'temperature' => 'hot', 'notes' => 'x',
            ]);
            $leadUnrelated = Lead::create([
                'prospect_id' => $prospectUnrelated->id, 'assigned_to' => $unrelatedEmployee->id, 'created_by' => $unrelatedEmployee->id,
                'stage' => 'requirement_collection', 'temperature' => 'hot', 'notes' => 'x',
            ]);

            $this->actingAs($manager);
            $ids = $this->leadIdsOnBoard();

            $this->assertContains($leadReportee->id, $ids, "A Manager's board must show their direct report's Lead.");
            $this->assertNotContains($leadUnrelated->id, $ids, "A Manager's board must never show an unrelated Employee's Lead.");
        });
    }

    public function test_a_senior_manager_sees_every_lead_in_their_organization(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $seniorManager = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Admin]);
            $manager = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Manager, 'manager_id' => $seniorManager->id]);
            $employee = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Employee, 'manager_id' => $manager->id]);
            $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
            $lead = Lead::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $employee->id, 'created_by' => $employee->id,
                'stage' => 'requirement_collection', 'temperature' => 'hot', 'notes' => 'x',
            ]);

            $this->actingAs($seniorManager);
            $ids = $this->leadIdsOnBoard();

            $this->assertContains($lead->id, $ids, 'A Senior Manager sees every Lead across their whole organization.');
        });
    }

    /**
     * The hard tenant-isolation guarantee — a card from a DIFFERENT
     * organization must never leak onto this board, regardless of role.
     * OrganizationScope::apply() fails closed with no active TenantContext,
     * so — exactly like every other reflection-driven test in this phase —
     * both fixture setup AND the getLanes() call that depends on
     * tenant-scoped queries must happen inside the SAME Tenancy::runAs()
     * closure; TenantContext is restored to its prior value the moment the
     * closure returns, so a call made after it returns silently sees
     * nothing rather than leaking across organizations.
     */
    public function test_a_lead_belonging_to_a_different_organization_never_appears_on_the_board(): void
    {
        $orgOne = Organization::factory()->create();
        $orgTwo = Organization::factory()->create();

        Tenancy::runAs($orgTwo->id, function () use ($orgTwo) {
            $user = User::factory()->create(['organization_id' => $orgTwo->id, 'role' => UserRole::Admin]);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            Lead::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'stage' => 'requirement_collection', 'temperature' => 'hot', 'notes' => 'cross-org lead',
            ]);
        });

        Tenancy::runAs($orgOne->id, function () use ($orgOne) {
            $userOne = User::factory()->create(['organization_id' => $orgOne->id, 'role' => UserRole::Admin]);
            $prospect = Prospect::factory()->create(['assigned_to' => $userOne->id, 'created_by' => $userOne->id]);
            $lead = Lead::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $userOne->id, 'created_by' => $userOne->id,
                'stage' => 'requirement_collection', 'temperature' => 'hot', 'notes' => 'x',
            ]);

            $this->actingAs($userOne);
            $ids = $this->leadIdsOnBoard();

            $this->assertContains($lead->id, $ids);
            $this->assertCount(1, $ids, "Organization One's board must show exactly its own Lead — never Organization Two's.");
        });
    }
}
