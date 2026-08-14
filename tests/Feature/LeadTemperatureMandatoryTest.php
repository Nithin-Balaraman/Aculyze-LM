<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Enums\LeadTemperature;
use App\Filament\Resources\LeadResource\Pages\CreateLead;
use App\Models\Lead;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Mandatory Fields batch: Temperature is required on Leads. The form
 * already had ->required() with a default before this batch; what's new
 * here is the model-level guard (checked unconditionally — unlike Follow Up
 * At/Appointment At, every existing write path, including
 * CallRoutingService::createLead(), already always sets it, so there's no
 * legitimate blank case to exempt).
 */
class LeadTemperatureMandatoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_lead_with_temperature_succeeds(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create();
        $this->actingAs($admin);

        Livewire::test(CreateLead::class)
            ->fillForm([
                'prospect_id' => $prospect->id,
                'assigned_to' => $prospect->assigned_to,
                'stage' => LeadStage::RequirementCollection->value,
                'temperature' => LeadTemperature::Hot->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(LeadTemperature::Hot, Lead::sole()->temperature);
    }

    public function test_model_guard_rejects_a_lead_saved_without_temperature(): void
    {
        $this->expectException(\LogicException::class);

        $prospect = Prospect::factory()->create();

        Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'stage' => LeadStage::RequirementCollection,
        ]);
    }

    public function test_model_guard_rejects_explicitly_blanking_temperature_on_an_existing_lead(): void
    {
        $prospect = Prospect::factory()->create();
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'stage' => LeadStage::RequirementCollection,
            'temperature' => LeadTemperature::Warm,
        ]);

        $this->expectException(\LogicException::class);

        $lead->forceFill(['temperature' => null])->save();
    }
}
