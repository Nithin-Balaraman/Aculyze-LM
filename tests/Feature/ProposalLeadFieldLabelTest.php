<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Filament\Resources\ProposalResource\Pages\CreateProposal;
use App\Filament\Resources\ProposalResource\Pages\EditProposal;
use App\Filament\Resources\ProposalResource\Pages\ViewProposal;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Lead Select on Proposal's Create/Edit/View form is
 * ->relationship('lead', 'id', modifyQueryUsing: ...) with
 * ->getOptionLabelFromRecordUsing() to show "{Company} — {Lead stage}"
 * instead of the raw lead_id. Filament resolves that label for the
 * CURRENTLY selected value asynchronously via
 * $wire.getFormSelectOptionLabel('data.lead_id') (a real browser-JS call,
 * matching how ProspectView's search-bar assets were investigated
 * elsewhere in this app) — calling it directly here is the decisive test,
 * since a plain rendered-HTML scrape can't see it (the <select>'s options
 * are empty server-side; only the async call resolves the label).
 *
 * The bug: modifyQueryUsing excluded any Lead that already has a Proposal
 * (whereDoesntHave('proposal'), needed so Create's dropdown doesn't offer
 * an already-claimed Lead) — but on Edit/View, the field's OWN current
 * value is exactly that Proposal's already-linked Lead, so it was
 * excluded from the very query used to resolve its own label, and
 * getFormSelectOptionLabel() came back null — which is why the browser
 * fell back to displaying the raw lead_id instead of the company name.
 */
class ProposalLeadFieldLabelTest extends TestCase
{
    use RefreshDatabase;

    private function validatedLead(User $owner, string $companyName): Lead
    {
        $prospect = Prospect::factory()->create(['company_name' => $companyName, 'assigned_to' => $owner->id, 'created_by' => $owner->id]);

        return Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'stage' => LeadStage::Validated,
            'temperature' => 'hot',
            'notes' => 'Validated in test fixture.',
        ]);
    }

    public function test_create_page_shows_the_company_name_for_a_deep_linked_lead_not_the_raw_id(): void
    {
        $employee = User::factory()->create();
        $lead = $this->validatedLead($employee, 'Acme Widgets Co');

        $this->actingAs($employee);

        // CreateProposal::mount() reads request()->query('lead_id') (the
        // deep-link from a validated Lead's "Create Proposal" row action)
        // — not a Livewire mount parameter, since mount() takes none — so
        // Livewire::withQueryParams() is what actually exercises that path
        // in a test (a plain request()->query->set() doesn't survive:
        // Livewire::test() boots its own fresh request for the component).
        Livewire::withQueryParams(['lead_id' => (string) $lead->id]);

        $label = Livewire::test(CreateProposal::class)
            ->instance()
            ->getFormSelectOptionLabel('data.lead_id');

        $this->assertSame('Acme Widgets Co — '.$lead->stage->getLabel(), $label);
        $this->assertNotSame((string) $lead->id, $label);
    }

    public function test_edit_page_shows_the_company_name_for_the_proposals_own_lead_not_the_raw_id(): void
    {
        $employee = User::factory()->create();
        $lead = $this->validatedLead($employee, 'Acme Widgets Co');
        $proposal = Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $lead->prospect_id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => 'being_prepared',
        ]);

        $this->actingAs($employee);

        $label = Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->instance()
            ->getFormSelectOptionLabel('data.lead_id');

        $this->assertSame('Acme Widgets Co — '.$lead->stage->getLabel(), $label);
        $this->assertNotSame((string) $lead->id, $label);
        $this->assertNotNull($label);
    }

    public function test_view_page_shows_the_company_name_for_the_proposals_own_lead_not_the_raw_id(): void
    {
        $employee = User::factory()->create();
        $lead = $this->validatedLead($employee, 'Acme Widgets Co');
        $proposal = Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $lead->prospect_id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => 'being_prepared',
        ]);

        $this->actingAs($employee);

        $label = Livewire::test(ViewProposal::class, ['record' => $proposal->getRouteKey()])
            ->instance()
            ->getFormSelectOptionLabel('data.lead_id');

        $this->assertSame('Acme Widgets Co — '.$lead->stage->getLabel(), $label);
        $this->assertNotSame((string) $lead->id, $label);
    }

    /**
     * Regression guard for the rule that motivated whereDoesntHave() in
     * the first place: a Lead belonging to a DIFFERENT, already-claimed
     * Proposal must still be excluded from the Create dropdown's options
     * — the orWhereKey() escape hatch only lets a Proposal's own current
     * Lead back in, not every claimed Lead.
     */
    public function test_create_page_still_excludes_a_lead_already_claimed_by_a_different_proposal(): void
    {
        $employee = User::factory()->create();
        $claimedLead = $this->validatedLead($employee, 'Already Claimed Co');
        Proposal::create([
            'lead_id' => $claimedLead->id,
            'prospect_id' => $claimedLead->prospect_id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => 'being_prepared',
        ]);
        $unclaimedLead = $this->validatedLead($employee, 'Still Available Co');

        $this->actingAs($employee);

        // getFormSelectOptions() returns a list of ['label' => ..., 'value'
        // => ..., 'disabled' => ...] entries, not a plain [id => label] map.
        $optionValues = collect(Livewire::test(CreateProposal::class)
            ->instance()
            ->getFormSelectOptions('data.lead_id'))
            ->pluck('value')
            ->all();

        $this->assertNotContains((string) $claimedLead->id, $optionValues);
        $this->assertContains((string) $unclaimedLead->id, $optionValues);
    }
}
