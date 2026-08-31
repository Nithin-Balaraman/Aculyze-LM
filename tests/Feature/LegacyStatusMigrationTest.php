<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\LeadStatus;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 2: php artisan aculyze:backfill-lead-appointment-status must apply
 * the EXACT conservative mapping approved for the Phase 2 plan — the
 * legacy `stage` column is never modified, no Demo/Proposal is ever
 * fabricated, and no AppointmentOutcome is ever invented. Mirrors
 * OrganizationBackfillTest's pattern: simulate genuinely pre-existing,
 * not-yet-backfilled data by inserting directly with organization_id
 * bypassed via the system task, and status left NULL.
 */
class LegacyStatusMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `leads.status`/`appointments.status` are NOT NULL (with a DB
     * default) in a freshly-migrated test database — the final tightening
     * step already ran. Relaxed here, for these tests only, to genuinely
     * simulate pre-Phase-2 production data where the column simply didn't
     * exist yet — safe, since RefreshDatabase fully re-migrates before the
     * next test regardless (mirrors OrganizationBackfillTest's identical
     * technique for organization_id).
     */
    private function legacyLead(Organization $org, User $user, string $stage): int
    {
        DB::statement('ALTER TABLE leads MODIFY status VARCHAR(255) NULL');

        return Tenancy::runAs($org->id, function () use ($org, $user, $stage) {
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

            return DB::table('leads')->insertGetId([
                'organization_id' => $org->id,
                'prospect_id' => $prospect->id,
                'assigned_to' => $user->id,
                'created_by' => $user->id,
                'stage' => $stage,
                'status' => null,
                'temperature' => 'warm',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    private function legacyAppointment(Organization $org, User $user, string $stage): int
    {
        DB::statement('ALTER TABLE appointments MODIFY status VARCHAR(255) NULL');

        return Tenancy::runAs($org->id, function () use ($org, $user, $stage) {
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

            return DB::table('appointments')->insertGetId([
                'organization_id' => $org->id,
                'prospect_id' => $prospect->id,
                'assigned_to' => $user->id,
                'created_by' => $user->id,
                'appointment_at' => now()->addDay(),
                'stage' => $stage,
                'status' => null,
                'outcome' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_demo_scheduled_or_done_legacy_lead_maps_to_requirement_confirmed_while_stage_is_unchanged(): void
    {
        $org = Organization::factory()->create();
        $user = Tenancy::runAs($org->id, fn () => User::factory()->create(['organization_id' => $org->id]));
        $id = $this->legacyLead($org, $user, 'demo_scheduled_or_done');

        $this->artisan('aculyze:backfill-lead-appointment-status')->assertSuccessful();

        $row = DB::table('leads')->find($id);
        $this->assertSame('demo_scheduled_or_done', $row->stage);
        $this->assertSame(LeadStatus::RequirementConfirmed->value, $row->status);
    }

    public function test_validated_legacy_lead_maps_to_requirement_confirmed_while_stage_is_unchanged(): void
    {
        $org = Organization::factory()->create();
        $user = Tenancy::runAs($org->id, fn () => User::factory()->create(['organization_id' => $org->id]));
        $id = $this->legacyLead($org, $user, 'validated');

        $this->artisan('aculyze:backfill-lead-appointment-status')->assertSuccessful();

        $row = DB::table('leads')->find($id);
        $this->assertSame('validated', $row->stage);
        $this->assertSame(LeadStatus::RequirementConfirmed->value, $row->status);
    }

    public function test_requirement_collection_legacy_lead_maps_directly(): void
    {
        $org = Organization::factory()->create();
        $user = Tenancy::runAs($org->id, fn () => User::factory()->create(['organization_id' => $org->id]));
        $id = $this->legacyLead($org, $user, 'requirement_collection');

        $this->artisan('aculyze:backfill-lead-appointment-status')->assertSuccessful();

        $this->assertSame(LeadStatus::RequirementCollection->value, DB::table('leads')->find($id)->status);
    }

    public function test_appointment_made_maps_to_scheduled(): void
    {
        $org = Organization::factory()->create();
        $user = Tenancy::runAs($org->id, fn () => User::factory()->create(['organization_id' => $org->id]));
        $id = $this->legacyAppointment($org, $user, 'appointment_made');

        $this->artisan('aculyze:backfill-lead-appointment-status')->assertSuccessful();

        $row = DB::table('appointments')->find($id);
        $this->assertSame(AppointmentStatus::Scheduled->value, $row->status);
        $this->assertNull($row->outcome);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('terminalAppointmentStages')]
    public function test_terminal_legacy_appointment_stages_map_to_completed_with_stage_preserved_and_no_outcome_fabricated(string $stage): void
    {
        $org = Organization::factory()->create();
        $user = Tenancy::runAs($org->id, fn () => User::factory()->create(['organization_id' => $org->id]));
        $id = $this->legacyAppointment($org, $user, $stage);

        $this->artisan('aculyze:backfill-lead-appointment-status')->assertSuccessful();

        $row = DB::table('appointments')->find($id);
        $this->assertSame($stage, $row->stage);
        $this->assertSame(AppointmentStatus::Completed->value, $row->status);
        $this->assertNull($row->outcome);
    }

    public static function terminalAppointmentStages(): array
    {
        return [
            ['visit_conducted'],
            ['discussion_completed'],
            ['succeeded'],
            ['not_succeeded'],
        ];
    }

    public function test_backfill_is_idempotent(): void
    {
        $org = Organization::factory()->create();
        $user = Tenancy::runAs($org->id, fn () => User::factory()->create(['organization_id' => $org->id]));
        $id = $this->legacyLead($org, $user, 'requirement_collection');

        $this->artisan('aculyze:backfill-lead-appointment-status')->assertSuccessful();
        $this->artisan('aculyze:backfill-lead-appointment-status')->assertSuccessful();

        $this->assertSame(LeadStatus::RequirementCollection->value, DB::table('leads')->find($id)->status);
    }

    public function test_backfill_refuses_to_run_and_changes_nothing_on_an_unknown_legacy_stage_value(): void
    {
        $org = Organization::factory()->create();
        $user = Tenancy::runAs($org->id, fn () => User::factory()->create(['organization_id' => $org->id]));
        $rowId = $this->legacyLead($org, $user, 'requirement_collection');
        DB::table('leads')->where('id', $rowId)->update(['stage' => 'some_future_stage_value']);

        $this->expectException(RuntimeException::class);

        try {
            $this->artisan('aculyze:backfill-lead-appointment-status')->run();
        } finally {
            $this->assertNull(DB::table('leads')->find($rowId)->status);
        }
    }

    public function test_verify_reports_zero_null_status_rows_after_a_full_backfill(): void
    {
        $org = Organization::factory()->create();
        $user = Tenancy::runAs($org->id, fn () => User::factory()->create(['organization_id' => $org->id]));
        $this->legacyLead($org, $user, 'requirement_collection');
        $this->legacyAppointment($org, $user, 'appointment_made');

        $this->artisan('aculyze:backfill-lead-appointment-status')
            ->expectsOutputToContain('Verification passed')
            ->assertSuccessful();
    }
}
