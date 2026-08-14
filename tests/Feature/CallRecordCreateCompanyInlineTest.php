<?php

namespace Tests\Feature;

use App\Filament\Resources\CallRecordResource\Pages\CreateCallRecord;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 2 item #4: the Call Record's Company field always offers a
 * "+ Create new company…" row in its search results (see
 * CallRecordResource::CREATE_NEW_PROSPECT and the field's
 * getSearchResultsUsing()/afterStateUpdated() closures), which opens the
 * same modal as the field's own "createProspect" suffix action "+" button
 * via Filament's standard mountFormComponentAction() trigger. Deliberately
 * a plain suffixAction rather than ->relationship()+createOptionForm() —
 * the latter combination was tried first and broke the dropdown-row click
 * in the browser (see CallRecordResource::form()'s comment on the field for
 * the full story).
 *
 * The dropdown-row rendering itself (the live search UI) isn't something
 * PHPUnit/Livewire testing can exercise — this covers the parts that are
 * testable: the modal's own create-and-select behavior, and the sentinel
 * value never surviving as a real prospect_id.
 */
class CallRecordCreateCompanyInlineTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_new_company_via_the_create_option_modal_persists_it_and_selects_it_on_the_form(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        Livewire::test(CreateCallRecord::class)
            ->mountFormComponentAction('prospect_id', 'createProspect')
            ->setFormComponentActionData([
                'company_name' => 'Brand New Co',
                'contact_person' => 'Priya Nair',
            ])
            ->callMountedFormComponentAction();

        $prospect = Prospect::where('company_name', 'Brand New Co')->sole();
        $this->assertSame($employee->id, $prospect->created_by);
        $this->assertSame($employee->id, $prospect->assigned_to);
    }

    public function test_creating_a_new_company_via_the_create_option_modal_updates_the_form_state_to_the_new_prospect(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        Livewire::test(CreateCallRecord::class)
            ->mountFormComponentAction('prospect_id', 'createProspect')
            ->setFormComponentActionData(['company_name' => 'Another New Co'])
            ->callMountedFormComponentAction()
            ->assertFormSet([
                'prospect_id' => Prospect::where('company_name', 'Another New Co')->value('id'),
            ]);
    }

    public function test_selecting_the_sentinel_value_resets_the_field_instead_of_saving_it_as_a_prospect(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        Livewire::test(CreateCallRecord::class)
            ->fillForm(['prospect_id' => '__create_new_prospect__'])
            ->assertFormSet(['prospect_id' => null]);
    }

    public function test_submitting_right_after_selecting_the_sentinel_fails_required_validation_instead_of_saving_it(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        Livewire::test(CreateCallRecord::class)
            ->fillForm(['prospect_id' => '__create_new_prospect__'])
            ->call('create')
            ->assertHasFormErrors(['prospect_id']);

        $this->assertDatabaseCount('call_records', 0);
    }
}
