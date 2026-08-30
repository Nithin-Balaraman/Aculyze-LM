<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Phase 1: App\Models\User's own booted() guard enforces the Employee ->
 * Manager -> Senior Manager hierarchy server-side — never relying on
 * Filament UI filtering alone. Covers both outgoing checks (is manager_id
 * itself a valid target?) and incoming checks (does a role/organization
 * change on THIS user invalidate people who already report to them, or
 * strand records they already own?).
 */
class HierarchyValidationTest extends TestCase
{
    use RefreshDatabase;

    private function org(): Organization
    {
        return Organization::factory()->create();
    }

    private function userIn(Organization $organization, UserRole $role): User
    {
        return Tenancy::runAs($organization->id, fn () => User::factory()->create(['organization_id' => $organization->id, 'role' => $role]));
    }

    // --- Outgoing: is manager_id itself valid? ---

    public function test_employee_can_report_to_a_manager(): void
    {
        $org = $this->org();
        $manager = $this->userIn($org, UserRole::Manager);
        $employee = $this->userIn($org, UserRole::Employee);

        $employee->update(['manager_id' => $manager->id]);

        $this->assertSame($manager->id, $employee->fresh()->manager_id);
    }

    public function test_manager_can_report_to_a_senior_manager(): void
    {
        $org = $this->org();
        $senior = $this->userIn($org, UserRole::Admin);
        $manager = $this->userIn($org, UserRole::Manager);

        $manager->update(['manager_id' => $senior->id]);

        $this->assertSame($senior->id, $manager->fresh()->manager_id);
    }

    public function test_employee_cannot_report_directly_to_a_senior_manager(): void
    {
        $org = $this->org();
        $senior = $this->userIn($org, UserRole::Admin);
        $employee = $this->userIn($org, UserRole::Employee);

        $this->expectException(LogicException::class);

        $employee->update(['manager_id' => $senior->id]);
    }

    public function test_manager_cannot_report_to_another_manager(): void
    {
        $org = $this->org();
        $managerA = $this->userIn($org, UserRole::Manager);
        $managerB = $this->userIn($org, UserRole::Manager);

        $this->expectException(LogicException::class);

        $managerA->update(['manager_id' => $managerB->id]);
    }

    public function test_senior_manager_cannot_have_a_manager(): void
    {
        $org = $this->org();
        $senior = $this->userIn($org, UserRole::Admin);
        $anotherManager = $this->userIn($org, UserRole::Manager);

        $this->expectException(LogicException::class);

        $senior->update(['manager_id' => $anotherManager->id]);
    }

    public function test_user_cannot_report_to_themselves(): void
    {
        $org = $this->org();
        $manager = $this->userIn($org, UserRole::Manager);

        $this->expectException(LogicException::class);

        $manager->update(['manager_id' => $manager->id]);
    }

    public function test_manager_reporting_to_a_manager_in_a_different_organization_is_rejected(): void
    {
        $orgA = $this->org();
        $orgB = $this->org();
        $seniorB = $this->userIn($orgB, UserRole::Admin);
        $managerA = $this->userIn($orgA, UserRole::Manager);

        $this->expectException(LogicException::class);

        $managerA->update(['manager_id' => $seniorB->id]);
    }

    public function test_circular_reporting_chain_is_rejected(): void
    {
        $org = $this->org();
        $senior = $this->userIn($org, UserRole::Admin);
        $manager = $this->userIn($org, UserRole::Manager);
        $manager->update(['manager_id' => $senior->id]);

        // Attempting to make the Senior Manager report to the Manager who
        // themselves reports to that Senior Manager would be a two-node
        // cycle — already impossible via the tier-adjacency check alone
        // (a Senior Manager cannot have any manager_id at all), so this
        // confirms that check fires rather than a silent cycle forming.
        $this->expectException(LogicException::class);

        $senior->update(['manager_id' => $manager->id]);
    }

    // --- Incoming: does a role/organization change on THIS user
    // invalidate existing relationships? ---

    public function test_role_change_is_rejected_while_direct_reports_still_depend_on_the_old_tier(): void
    {
        $org = $this->org();
        $senior = $this->userIn($org, UserRole::Admin);
        $manager = $this->userIn($org, UserRole::Manager);
        $manager->update(['manager_id' => $senior->id]);

        $report = $this->userIn($org, UserRole::Employee);
        $report->update(['manager_id' => $manager->id]);

        $this->expectException(LogicException::class);

        $manager->fresh()->update(['role' => UserRole::Employee]);
    }

    public function test_role_change_succeeds_once_direct_reports_are_reassigned_first(): void
    {
        $org = $this->org();
        $senior = $this->userIn($org, UserRole::Admin);
        $manager = $this->userIn($org, UserRole::Manager);
        $otherManager = $this->userIn($org, UserRole::Manager);
        $otherManager->update(['manager_id' => $senior->id]);

        $report = $this->userIn($org, UserRole::Employee);
        $report->update(['manager_id' => $manager->id]);

        // Reassign first...
        $report->update(['manager_id' => $otherManager->id]);

        // ...then the role change (and clearing this now-report-free
        // manager's own manager_id, since a demoted Employee has no
        // manager_id of its own from being a Manager) succeeds.
        $manager->fresh()->update(['role' => UserRole::Employee]);

        $this->assertSame(UserRole::Employee, $manager->fresh()->role);
    }

    public function test_organization_change_is_rejected_while_records_are_still_owned_in_the_old_organization(): void
    {
        $org = $this->org();
        $employee = $this->userIn($org, UserRole::Employee);
        Tenancy::runAs($org->id, fn () => Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]));

        $otherOrg = $this->org();

        $this->expectException(LogicException::class);

        $employee->forceFill(['organization_id' => $otherOrg->id])->save();
    }

    public function test_organization_change_is_rejected_while_user_has_direct_reports(): void
    {
        $org = $this->org();
        $senior = $this->userIn($org, UserRole::Admin);
        $manager = $this->userIn($org, UserRole::Manager);
        $manager->update(['manager_id' => $senior->id]);

        $report = $this->userIn($org, UserRole::Employee);
        $report->update(['manager_id' => $manager->id]);

        $otherOrg = $this->org();

        $this->expectException(LogicException::class);

        $manager->fresh()->forceFill(['organization_id' => $otherOrg->id])->save();
    }

    public function test_organization_change_succeeds_for_a_user_with_no_dependents_or_owned_records(): void
    {
        $org = $this->org();
        $employee = $this->userIn($org, UserRole::Employee);
        $otherOrg = $this->org();

        $employee->forceFill(['organization_id' => $otherOrg->id])->save();

        $this->assertSame($otherOrg->id, $employee->fresh()->organization_id);
    }
}
