<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CallRecord;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 1: App\Models\Concerns\EnforcesSameOrganizationRelations must
 * reject a record whose own organization_id is correct but whose foreign
 * key (prospect_id, lead_id, user_id, manager_id, etc.) points at a
 * different organization's row — the crafted-request path that a bare
 * organization_id check alone would miss. Every case here attempts a real
 * mutation (create/update), not just a read.
 */
class CrossOrganizationRelationshipInjectionTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgWithUser(UserRole $role = UserRole::Employee): array
    {
        $organization = Organization::factory()->create();
        $user = Tenancy::runAs(
            $organization->id,
            fn () => User::factory()->create(['organization_id' => $organization->id, 'role' => $role])
        );

        return [$organization, $user];
    }

    public function test_creating_a_call_record_referencing_another_organizations_prospect_is_rejected(): void
    {
        [$orgB, $ownerB] = $this->makeOrgWithUser();
        $prospectB = Tenancy::runAs($orgB->id, fn () => Prospect::factory()->create(['assigned_to' => $ownerB->id, 'created_by' => $ownerB->id]));

        [$orgA, $employeeA] = $this->makeOrgWithUser();

        $this->actingAs($employeeA);

        // organization_id is deliberately not mass-assignable (see
        // BelongsToOrganization), so it auto-derives from prospect_id
        // regardless of what's requested — this CallRecord would inherit
        // Org B from the Prospect, at which point its own user_id (an Org A
        // employee) is what EnforcesSameOrganizationRelations catches. The
        // net effect either way is what matters: a crafted cross-org
        // reference is rejected, not silently split across organizations.
        $this->expectException(RuntimeException::class);

        CallRecord::create([
            'prospect_id' => $prospectB->id,
            'user_id' => $employeeA->id,
            'called_at' => now(),
            'outcome' => 'no_answer',
        ]);
    }

    public function test_creating_a_proposal_referencing_another_organizations_lead_is_rejected(): void
    {
        [$orgB, $ownerB] = $this->makeOrgWithUser();
        [$prospectB, $leadB] = Tenancy::runAs($orgB->id, function () use ($ownerB) {
            $prospect = Prospect::factory()->create(['assigned_to' => $ownerB->id, 'created_by' => $ownerB->id]);
            $lead = Lead::create([
                'prospect_id' => $prospect->id,
                'assigned_to' => $ownerB->id,
                'created_by' => $ownerB->id,
                'stage' => 'validated',
                'temperature' => 'hot',
                'notes' => 'Confirmed.',
            ]);

            return [$prospect, $lead];
        });

        [$orgA, $employeeA] = $this->makeOrgWithUser();
        $prospectA = Tenancy::runAs($orgA->id, fn () => Prospect::factory()->create(['assigned_to' => $employeeA->id, 'created_by' => $employeeA->id]));

        $this->actingAs($employeeA);

        $this->expectException(RuntimeException::class);

        \App\Models\Proposal::create([
            'lead_id' => $leadB->id,
            'prospect_id' => $prospectA->id,
            'assigned_to' => $employeeA->id,
            'created_by' => $employeeA->id,
            'stage' => 'being_prepared',
        ]);
    }

    public function test_assigning_a_prospect_to_another_organizations_user_is_rejected(): void
    {
        [$orgB, $userB] = $this->makeOrgWithUser();

        [$orgA, $employeeA] = $this->makeOrgWithUser();
        $prospectA = Tenancy::runAs($orgA->id, fn () => Prospect::factory()->create(['assigned_to' => $employeeA->id, 'created_by' => $employeeA->id]));

        $this->actingAs($employeeA);

        $this->expectException(RuntimeException::class);

        $prospectA->update(['assigned_to' => $userB->id]);
    }

    public function test_manager_in_org_a_cannot_set_manager_id_to_a_user_in_org_b(): void
    {
        [$orgB, $managerB] = $this->makeOrgWithUser(UserRole::Manager);

        [$orgA, $employeeA] = $this->makeOrgWithUser(UserRole::Employee);

        $this->actingAs($employeeA);

        $this->expectException(\LogicException::class);

        $employeeA->update(['manager_id' => $managerB->id]);
    }

    public function test_creating_a_lead_referencing_another_organizations_call_record_is_rejected(): void
    {
        [$orgB, $ownerB] = $this->makeOrgWithUser();
        [$prospectB, $callB] = Tenancy::runAs($orgB->id, function () use ($ownerB) {
            $prospect = Prospect::factory()->create(['assigned_to' => $ownerB->id, 'created_by' => $ownerB->id]);
            $call = CallRecord::create([
                'prospect_id' => $prospect->id,
                'user_id' => $ownerB->id,
                'called_at' => now(),
                'outcome' => 'requirement_identified',
                'notes' => 'Interested.',
            ]);

            return [$prospect, $call];
        });

        [$orgA, $employeeA] = $this->makeOrgWithUser();
        $prospectA = Tenancy::runAs($orgA->id, fn () => Prospect::factory()->create(['assigned_to' => $employeeA->id, 'created_by' => $employeeA->id]));

        $this->actingAs($employeeA);

        $this->expectException(RuntimeException::class);

        Lead::create([
            'prospect_id' => $prospectA->id,
            'call_record_id' => $callB->id,
            'assigned_to' => $employeeA->id,
            'created_by' => $employeeA->id,
            'stage' => 'requirement_collection',
            'temperature' => 'warm',
        ]);
    }

    public function test_same_organization_relationship_assignment_still_succeeds(): void
    {
        // Positive control: the guard must not be so strict it blocks
        // legitimate same-organization operations.
        [$orgA, $employeeA] = $this->makeOrgWithUser();
        $colleagueA = Tenancy::runAs($orgA->id, fn () => User::factory()->create(['organization_id' => $orgA->id]));
        $prospectA = Tenancy::runAs($orgA->id, fn () => Prospect::factory()->create(['assigned_to' => $employeeA->id, 'created_by' => $employeeA->id]));

        $this->actingAs($employeeA);

        $prospectA->update(['assigned_to' => $colleagueA->id]);

        $this->assertSame($colleagueA->id, $prospectA->fresh()->assigned_to);
    }
}
