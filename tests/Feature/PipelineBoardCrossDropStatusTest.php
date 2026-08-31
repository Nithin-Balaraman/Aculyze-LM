<?php

namespace Tests\Feature;

use App\Enums\AppointmentStage;
use App\Enums\AppointmentStatus;
use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Filament\Pages\PipelineBoard;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Found while auditing every real Lead/Appointment creation path (Phase 2
 * verification round): PipelineBoard::createCrossDropDestination() — the
 * cross-lane drag-and-drop destination creator — created Appointments/
 * Leads without ever setting `status` explicitly, silently relying on the
 * NOT NULL column's DB default (scheduled/requirement_collection). That
 * default is WRONG whenever the destination stage isn't the default one —
 * e.g. cross-dropping straight onto Appointment's "Succeeded" box created
 * a row with stage=succeeded but status=scheduled, an inconsistent pair
 * the legacy-backfill mapping would never produce.
 *
 * Fixed by deriving `status` from the destination stage via
 * AppointmentStatus::fromLegacyStage()/LeadStatus::fromLegacyStage() — the
 * same single source of truth App\Console\Commands\
 * BackfillLeadAppointmentStatus uses, so the two can never diverge.
 */
class PipelineBoardCrossDropStatusTest extends TestCase
{
    use RefreshDatabase;

    private function invokeCreateCrossDropDestination(PipelineBoard $board, string $destResource, string $destStage, $source, array $data = []): void
    {
        $method = new \ReflectionMethod($board, 'createCrossDropDestination');
        $method->setAccessible(true);
        $method->invoke($board, $destResource, $destStage, $source, $data);
    }

    public function test_cross_drop_creating_an_appointment_at_a_terminal_stage_gets_the_matching_status_not_the_blind_default(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $lead = Lead::create([
                'prospect_id' => $prospect->id,
                'assigned_to' => $user->id,
                'created_by' => $user->id,
                'stage' => LeadStage::Validated,
                'status' => LeadStatus::RequirementConfirmed,
                'temperature' => 'hot',
                'notes' => 'Confirmed.',
            ]);

            $this->invokeCreateCrossDropDestination(
                app(PipelineBoard::class), 'appointment', AppointmentStage::Succeeded->value, $lead,
                ['destination_appointment_at' => now()->addDay(), 'destination_outcome_notes' => 'Went well.']
            );

            $appointment = Appointment::query()->where('prospect_id', $prospect->id)->firstOrFail();
            $this->assertSame(AppointmentStage::Succeeded, $appointment->stage);
            $this->assertSame(AppointmentStatus::Completed, $appointment->status, 'Succeeded must not be left at the blind Scheduled default.');
        });
    }

    public function test_cross_drop_creating_an_appointment_at_the_default_stage_still_gets_scheduled(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $lead = Lead::create([
                'prospect_id' => $prospect->id,
                'assigned_to' => $user->id,
                'created_by' => $user->id,
                'stage' => LeadStage::Validated,
                'status' => LeadStatus::RequirementConfirmed,
                'temperature' => 'hot',
                'notes' => 'Confirmed.',
            ]);

            $this->invokeCreateCrossDropDestination(
                app(PipelineBoard::class), 'appointment', AppointmentStage::AppointmentMade->value, $lead,
                ['destination_appointment_at' => now()->addDay()]
            );

            $appointment = Appointment::query()->where('prospect_id', $prospect->id)->firstOrFail();
            $this->assertSame(AppointmentStatus::Scheduled, $appointment->status);
        });
    }

    public function test_cross_drop_creating_a_lead_at_the_validated_stage_gets_requirement_confirmed_not_the_blind_default(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $followUp = \App\Models\FollowUp::create([
                'prospect_id' => $prospect->id,
                'user_id' => $user->id,
                'follow_up_at' => now()->addDay(),
                'reason' => 'Callback requested',
                'status' => 'pending',
            ]);

            $this->invokeCreateCrossDropDestination(
                app(PipelineBoard::class), 'lead', LeadStage::Validated->value, $followUp,
                ['destination_notes' => 'Confirmed on the call.']
            );

            $lead = Lead::query()->where('prospect_id', $prospect->id)->firstOrFail();
            $this->assertSame(LeadStage::Validated, $lead->stage);
            $this->assertSame(LeadStatus::RequirementConfirmed, $lead->status, 'Validated must not be left at the blind RequirementCollection default.');
        });
    }
}
