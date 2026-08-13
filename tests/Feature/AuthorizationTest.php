<?php

namespace Tests\Feature;

use App\Models\CallRecord;
use App\Models\Lead;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies AGENTS.md sections 6, 7, 39: an Employee may only see their own
 * records (even by guessing another record's ID/URL) while Admin can see
 * and manage everything, including Employee accounts and the Main
 * Dashboard.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_cannot_view_another_employees_prospect(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);

        $this->actingAs($intruder)
            ->get("/admin/prospects/{$prospect->id}")
            ->assertNotFound();
    }

    public function test_employee_can_view_their_own_prospect(): void
    {
        $owner = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);

        $this->actingAs($owner)
            ->get("/admin/prospects/{$prospect->id}")
            ->assertOk();
    }

    public function test_employee_cannot_edit_another_employees_prospect(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);

        $this->actingAs($intruder)
            ->get("/admin/prospects/{$prospect->id}/edit")
            ->assertNotFound();
    }

    public function test_admin_can_view_any_employees_prospect(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);

        $this->actingAs($admin)
            ->get("/admin/prospects/{$prospect->id}")
            ->assertOk();
    }

    public function test_employee_cannot_view_another_employees_call_record(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $owner->id,
            'called_at' => now(),
            'outcome' => 'no_answer',
        ]);

        $this->actingAs($intruder)
            ->get("/admin/call-records/{$call->id}")
            ->assertNotFound();
    }

    public function test_employee_cannot_view_another_employees_lead(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'stage' => 'requirement_collection',
            'temperature' => 'warm',
        ]);

        $this->actingAs($intruder)
            ->get("/admin/leads/{$lead->id}")
            ->assertNotFound();
    }

    public function test_employee_cannot_manage_users(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->get('/admin/users')->assertForbidden();
        $this->actingAs($employee)->get('/admin/users/create')->assertForbidden();
    }

    public function test_admin_can_manage_users(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/users')->assertOk();
    }

    public function test_employee_cannot_access_main_dashboard(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->get('/admin/main-dashboard')->assertForbidden();
    }

    public function test_admin_can_access_main_dashboard(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/main-dashboard')->assertOk();
    }

    public function test_employee_cannot_view_another_employees_dashboard(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($employee)
            ->get("/admin/employee-dashboard/{$other->id}")
            ->assertForbidden();
    }

    public function test_employee_can_view_their_own_dashboard_by_id(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)
            ->get("/admin/employee-dashboard/{$employee->id}")
            ->assertOk();
    }

    public function test_admin_can_view_any_employees_dashboard(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create();

        $this->actingAs($admin)
            ->get("/admin/employee-dashboard/{$employee->id}")
            ->assertOk();
    }
}
