<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Filament\Pages\PipelineBoard;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Small fix: the Lead card's primary title now shows Opportunity Title
 * (falling back to the company name when absent) instead of always the
 * company name, so two independent Leads on the same company (see
 * MultipleLeadsPerCompanyTest) read as distinct cards on the board — the
 * whole reason opportunity_title exists in the first place. The company
 * name itself stays visible unconditionally as the small "kicker" row
 * above the title (`$card['company']`), so it is demoted to a secondary
 * line rather than removed. Lead-only: every other lane's card()/
 * stageBasedLane() call never supplies primaryTitleOf, so `primaryTitle`
 * stays null there and their rendered title is unchanged.
 */
class PipelineBoardLeadCardTitleTest extends TestCase
{
    use RefreshDatabase;

    private function leadLaneCards(): array
    {
        $method = new \ReflectionMethod(PipelineBoard::class, 'leadLane');
        $method->setAccessible(true);

        return $method->invoke(app(PipelineBoard::class))['cards'];
    }

    private function callLaneCards(): array
    {
        $method = new \ReflectionMethod(PipelineBoard::class, 'callLane');
        $method->setAccessible(true);

        return $method->invoke(app(PipelineBoard::class))['cards'];
    }

    private function makeLead(User $user, Prospect $prospect, array $overrides = []): Lead
    {
        return Lead::create(array_merge([
            'prospect_id' => $prospect->id,
            'assigned_to' => $user->id,
            'created_by' => $user->id,
            'stage' => LeadStage::RequirementCollection->value,
            'status' => LeadStatus::RequirementCollection,
            'temperature' => LeadTemperature::Warm,
        ], $overrides));
    }

    public function test_a_lead_card_shows_opportunity_title_as_primary_title_and_company_stays_as_the_kicker(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create([
                'assigned_to' => $user->id, 'created_by' => $user->id, 'company_name' => 'Acme Manufacturing',
            ]);
            $this->makeLead($user, $prospect, ['opportunity_title' => 'Inventory Automation']);

            $card = $this->leadLaneCards()[0];

            $this->assertSame('Inventory Automation', $card['primaryTitle']);
            $this->assertSame('Acme Manufacturing', $card['company']);
        });
    }

    public function test_a_legacy_lead_with_no_opportunity_title_falls_back_to_the_company_name_never_blank(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create([
                'assigned_to' => $user->id, 'created_by' => $user->id, 'company_name' => 'Legacy Co',
            ]);
            $this->makeLead($user, $prospect);

            $card = $this->leadLaneCards()[0];

            $this->assertNull($card['primaryTitle']);
            $this->assertSame('Legacy Co', $card['company']);
        });
    }

    public function test_two_independent_leads_for_the_same_company_show_distinct_primary_titles(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $this->makeLead($user, $prospect, ['opportunity_title' => 'ERP Requirement']);
            $this->makeLead($user, $prospect, ['opportunity_title' => 'Cybersecurity Assessment']);

            $titles = collect($this->leadLaneCards())->pluck('primaryTitle')->sort()->values()->all();

            $this->assertSame(['Cybersecurity Assessment', 'ERP Requirement'], $titles);
        });
    }

    /**
     * Scope guard: this fix must not touch any other lane's card — a Call
     * card's primaryTitle stays null (falls back to company name in
     * Blade), exactly as every non-Lead lane behaves.
     */
    public function test_other_lanes_are_unaffected_a_call_card_carries_no_primary_title_override(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            \App\Models\CallRecord::create([
                'prospect_id' => $prospect->id, 'user_id' => $user->id,
                'called_at' => now(), 'outcome' => \App\Enums\CallOutcome::NoAnswer,
            ]);

            $card = $this->callLaneCards()[0];

            $this->assertNull($card['primaryTitle']);
        });
    }
}
