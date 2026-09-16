<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Filament\Resources\LeadResource\Pages\EditLead;
use App\Filament\Resources\LeadResource\Pages\ViewLead;
use App\Models\Lead;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Display-only fix: Pipeline Board V2 (Calls column) added opportunity_title
 * to Lead (see CallRoutingService::createLead()), but LeadResource's own
 * View/Edit pages never surfaced it — both reuse LeadResource::formSchema()
 * (ViewLead is a plain Filament ViewRecord that disables the same form). No
 * creation/routing behavior changes here — this only makes an already-
 * persisted value visible and, on Edit, changeable.
 */
class LeadOpportunityTitleViewEditTest extends TestCase
{
    use RefreshDatabase;

    private function makeLead(User $user, array $overrides = []): Lead
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

        return Lead::create(array_merge([
            'prospect_id' => $prospect->id,
            'assigned_to' => $user->id,
            'created_by' => $user->id,
            'stage' => LeadStage::RequirementCollection->value,
            'status' => LeadStatus::RequirementCollection,
            'temperature' => LeadTemperature::Warm,
        ], $overrides));
    }

    public function test_view_page_shows_the_stored_opportunity_title(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $lead = $this->makeLead($user, ['opportunity_title' => 'Inventory Automation']);

        Livewire::test(ViewLead::class, ['record' => $lead->getKey()])
            ->assertFormSet(['opportunity_title' => 'Inventory Automation'])
            ->assertFormFieldIsDisabled('opportunity_title');
    }

    public function test_a_legacy_lead_predating_this_feature_shows_opportunity_title_blank_not_fabricated(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // No opportunity_title supplied — mirrors a Lead created before
        // this feature existed, or via any path other than Calls -> Lead
        // (standalone create, Others + CreateLead).
        $lead = $this->makeLead($user);

        Livewire::test(ViewLead::class, ['record' => $lead->getKey()])
            ->assertFormSet(['opportunity_title' => null]);
    }

    public function test_edit_page_allows_changing_opportunity_title(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $lead = $this->makeLead($user, ['opportunity_title' => 'Original Title']);

        Livewire::test(EditLead::class, ['record' => $lead->getKey()])
            ->fillForm(['opportunity_title' => 'Updated Opportunity Title'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Updated Opportunity Title', $lead->fresh()->opportunity_title);
    }
}
