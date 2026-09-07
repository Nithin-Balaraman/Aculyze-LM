<?php

namespace Tests\Feature;

use App\Enums\ProposalTaxComponentType;
use App\Enums\UserRole;
use App\Filament\Resources\ProposalResource\Pages\ManageCommercialVersion;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 4A-2.5: Draft editing through the Filament page (B) and optimistic
 * concurrency (C) — all persistence goes through
 * ProposalVersionDraftService; these tests confirm the Livewire form
 * correctly assembles and forwards data to it, never that the UI
 * recomputes anything itself (ProposalVersionDraftServiceTest already
 * covers the service's own contract in isolation).
 */
class ManageCommercialVersionDraftEditingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{employee: User, manager: User} */
    private function hierarchy(): array
    {
        $seniorManager = User::factory()->admin()->create();
        $manager = User::factory()->create(['role' => UserRole::Manager, 'manager_id' => $seniorManager->id]);
        $employee = User::factory()->create(['role' => UserRole::Employee, 'manager_id' => $manager->id]);

        return compact('employee', 'manager');
    }

    private function draftProposal(User $employee): Proposal
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id, 'company_name' => 'Original Prospect Name']);

        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => 'validated',
            'temperature' => 'hot',
            'notes' => 'Ready for Proposal.',
        ]);

        $proposal = Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => 'being_prepared',
        ]);

        $version = ProposalVersion::factory()->create(['proposal_id' => $proposal->id, 'version_number' => 1]);
        $proposal->forceFill(['current_version_id' => $version->id])->save();

        return $proposal;
    }

    public function test_manager_can_open_draft_editor(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->draftProposal($employee);
        $this->actingAs($manager);

        $component = Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()]);
        $this->assertTrue($component->instance()->isDraftEditable());
    }

    public function test_can_edit_customer_snapshot_fields(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->draftProposal($employee);
        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->set('draftData.customer_name_snapshot', 'Acme Corp')
            ->set('draftData.customer_gstin_snapshot', '33AAAAA0000A1Z5')
            ->set('draftData.billing_state_snapshot', 'Tamil Nadu')
            ->call('saveDraft')
            ->assertHasNoErrors();

        $version = $proposal->fresh()->currentVersion;
        $this->assertSame('Acme Corp', $version->customer_name_snapshot);
        $this->assertSame('33AAAAA0000A1Z5', $version->customer_gstin_snapshot);
        $this->assertSame('Tamil Nadu', $version->billing_state_snapshot);
    }

    public function test_prospect_master_data_is_not_changed_by_draft_edit(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->draftProposal($employee);
        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->set('draftData.customer_name_snapshot', 'A Totally Different Name')
            ->call('saveDraft')
            ->assertHasNoErrors();

        $this->assertSame('Original Prospect Name', $proposal->prospect->fresh()->company_name);
    }

    public function test_can_create_a_line(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->draftProposal($employee);
        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->set('draftData.lines', [['item_name' => 'Widget', 'quantity' => '2', 'unit_price' => '500']])
            ->call('saveDraft')
            ->assertHasNoErrors();

        $version = $proposal->fresh()->currentVersion;
        $this->assertSame(1, $version->lines()->count());
    }

    public function test_can_edit_a_line(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->draftProposal($employee);
        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->set('draftData.lines', [['item_name' => 'Widget', 'quantity' => '1', 'unit_price' => '100']])
            ->call('saveDraft');

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->set('draftData.lines', [['item_name' => 'Widget V2', 'quantity' => '3', 'unit_price' => '100']])
            ->call('saveDraft')
            ->assertHasNoErrors();

        $version = $proposal->fresh()->currentVersion;
        $this->assertSame(1, $version->lines()->count());
        $this->assertSame('Widget V2', $version->lines->first()->item_name);
    }

    public function test_can_delete_a_line(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->draftProposal($employee);
        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->set('draftData.lines', [
                ['item_name' => 'Widget', 'quantity' => '1', 'unit_price' => '100'],
                ['item_name' => 'Gadget', 'quantity' => '1', 'unit_price' => '50'],
            ])
            ->call('saveDraft');

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->set('draftData.lines', [['item_name' => 'Widget', 'quantity' => '1', 'unit_price' => '100']])
            ->call('saveDraft')
            ->assertHasNoErrors();

        $version = $proposal->fresh()->currentVersion;
        $this->assertSame(1, $version->lines()->count());
    }

    public function test_can_add_a_tax_component(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->draftProposal($employee);
        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->set('draftData.lines', [[
                'item_name' => 'Widget', 'quantity' => '1', 'unit_price' => '1000',
                'tax_components' => [['component_type' => ProposalTaxComponentType::Cgst->value, 'rate' => '9']],
            ]])
            ->call('saveDraft')
            ->assertHasNoErrors();

        $line = $proposal->fresh()->currentVersion->lines->first();
        $this->assertSame(1, $line->taxComponents->count());
        $this->assertSame('90.00', $line->taxComponents->first()->amount);
    }

    public function test_can_edit_and_delete_a_tax_component(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->draftProposal($employee);
        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->set('draftData.lines', [[
                'item_name' => 'Widget', 'quantity' => '1', 'unit_price' => '1000',
                'tax_components' => [
                    ['component_type' => ProposalTaxComponentType::Cgst->value, 'rate' => '9'],
                    ['component_type' => ProposalTaxComponentType::Sgst->value, 'rate' => '9'],
                ],
            ]])
            ->call('saveDraft');

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->set('draftData.lines', [[
                'item_name' => 'Widget', 'quantity' => '1', 'unit_price' => '1000',
                'tax_components' => [
                    ['component_type' => ProposalTaxComponentType::Igst->value, 'rate' => '18'],
                ],
            ]])
            ->call('saveDraft')
            ->assertHasNoErrors();

        $line = $proposal->fresh()->currentVersion->lines->first();
        $this->assertSame(1, $line->taxComponents->count());
        $this->assertSame(ProposalTaxComponentType::Igst, $line->taxComponents->first()->component_type);
        $this->assertSame('180.00', $line->taxComponents->first()->amount);
    }

    public function test_derived_values_are_recalculated_by_the_server(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->draftProposal($employee);
        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->set('draftData.lines', [['item_name' => 'Widget', 'quantity' => '2', 'unit_price' => '500']])
            ->call('saveDraft')
            ->assertHasNoErrors();

        $version = $proposal->fresh()->currentVersion;
        $this->assertSame('1000.00', $version->lines->first()->gross_amount);
    }

    /** The Draft editor's line payload contract has no derived-value keys at all — nothing to smuggle a tampered value through. */
    public function test_user_supplied_tampered_derived_values_do_not_become_authority(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->draftProposal($employee);
        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->set('draftData.lines', [[
                'item_name' => 'Widget', 'quantity' => '1', 'unit_price' => '100',
                'gross_amount' => '999999.99', 'line_total' => '999999.99',
            ]])
            ->call('saveDraft')
            ->assertHasNoErrors();

        $this->assertSame('100.00', $proposal->fresh()->currentVersion->lines->first()->gross_amount);
    }

    public function test_version_totals_displayed_from_persisted_calculation(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->draftProposal($employee);
        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->set('draftData.lines', [['item_name' => 'Widget', 'quantity' => '2', 'unit_price' => '500']])
            ->call('saveDraft')
            ->assertHasNoErrors()
            ->assertSee('1000.00');
    }

    // -----------------------------------------------------------------
    // C. OPTIMISTIC CONCURRENCY
    // -----------------------------------------------------------------

    public function test_stale_updated_at_save_is_rejected(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->draftProposal($employee);
        $this->actingAs($manager);

        $component = Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()]);
        $staleToken = $component->get('concurrencyToken');

        $this->travel(1)->minute();

        // Someone else saves first (a second, independent page instance).
        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->set('draftData.payment_terms', 'First save.')
            ->call('saveDraft');

        $component->set('concurrencyToken', $staleToken)
            ->set('draftData.payment_terms', 'Stale second save.')
            ->call('saveDraft')
            ->assertNotified();

        $this->assertSame('First save.', $proposal->fresh()->currentVersion->payment_terms);
    }

    public function test_rejected_stale_save_does_not_partially_alter_draft(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->draftProposal($employee);
        $this->actingAs($manager);

        $component = Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()]);
        $staleToken = $component->get('concurrencyToken');

        $this->travel(1)->minute();

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->set('draftData.payment_terms', 'First save.')
            ->call('saveDraft');

        $component->set('concurrencyToken', $staleToken)
            ->set('draftData.payment_terms', 'Stale second save.')
            ->set('draftData.lines', [['item_name' => 'Should not persist', 'quantity' => '1', 'unit_price' => '1']])
            ->call('saveDraft');

        $version = $proposal->fresh()->currentVersion;
        $this->assertSame('First save.', $version->payment_terms);
        $this->assertSame(0, $version->lines()->count());
    }

    public function test_fresh_reload_and_save_succeeds(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->draftProposal($employee);
        $this->actingAs($manager);

        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->set('draftData.payment_terms', 'First save.')
            ->call('saveDraft')
            ->assertHasNoErrors();

        // A fresh page load picks up the correct, current token.
        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->set('draftData.payment_terms', 'Second save.')
            ->call('saveDraft')
            ->assertHasNoErrors();

        $this->assertSame('Second save.', $proposal->fresh()->currentVersion->payment_terms);
    }

    public function test_concurrency_token_refreshes_on_the_page_after_a_successful_save(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->draftProposal($employee);
        $this->actingAs($manager);

        $component = Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()]);
        $originalToken = $component->get('concurrencyToken');

        $this->travel(1)->minute();

        $component->set('draftData.payment_terms', 'Updated.')
            ->call('saveDraft')
            ->assertHasNoErrors();

        $this->assertNotSame($originalToken, $component->get('concurrencyToken'));

        // The refreshed token is now immediately valid for another save.
        $component->set('draftData.payment_terms', 'Updated again.')
            ->call('saveDraft')
            ->assertHasNoErrors();

        $this->assertSame('Updated again.', $proposal->fresh()->currentVersion->payment_terms);
    }
}
