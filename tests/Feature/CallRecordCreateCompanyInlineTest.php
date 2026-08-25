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
 * getSearchResultsUsing() closure), which opens the same modal as the
 * field's own "createProspect" suffix action via Filament's standard
 * mountFormComponentAction() trigger. Deliberately a plain suffixAction
 * rather than ->relationship()+createOptionForm() — the latter combination
 * was tried first and broke the dropdown-row click in the browser (see
 * CallRecordResource::form()'s comment on the field for the full story).
 *
 * Clicking the row itself is wired up in client-side Alpine JS (see the
 * field's ->extraAlpineAttributes() in CallRecordResource::form()), which
 * PHPUnit/Livewire testing can't exercise. This covers the parts that are
 * testable: the modal's own create-and-select behavior via a direct
 * mountFormComponentAction() call (simulating what the Alpine watcher
 * triggers), and the sentinel value never surviving as a real prospect_id
 * (via the field's ->afterStateUpdated() reset, which runs independently of
 * the Alpine trigger).
 *
 * Note for anyone touching the raw Livewire component method: the real
 * component key is "data.prospect_id" (Filament's CreateRecord/EditRecord
 * pages nest all form fields under a "data." statePath prefix — see
 * CreateRecord::getFormStatePath() — hence the field's own
 * ->extraAlpineAttributes() call in CallRecordResource::form() hardcodes
 * that full key). This test file calls the *test helper*
 * ->mountFormComponentAction(), which auto-prepends the form's statePath
 * itself (see Filament\Forms\Testing\TestsComponentActions::
 * parseNestedFormComponentActionComponentAndName()) — so plain
 * 'prospect_id' is correct here; passing 'data.prospect_id' would double
 * the prefix and silently fail to resolve the component.
 */
class CallRecordCreateCompanyInlineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ProspectResource::formSchema() (reused verbatim by this inline
     * "createProspect" action) now requires every text field except
     * Telephone/Mobile (which need at least one of the two, not both) — see
     * ProspectFormMandatoryFieldsTest for that resource's own dedicated
     * coverage. These tests are about the inline-create/select mechanism
     * itself, not field validation, so a complete baseline keeps them
     * focused on that.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function completeProspectData(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Some Co',
            'contact_person' => 'Someone',
            'designation' => 'Manager',
            'telephone' => '+91 90000 00000',
            'email' => 'someone@example.com',
            'website' => 'https://example.com',
            'industry' => 'Manufacturing',
            'source' => 'Referral',
            'address' => '1 Some Street',
            'locality' => 'Some Locality',
            'city' => 'Some City',
            'state' => 'Some State',
            'pincode' => '000000',
            'notes' => 'Some notes.',
        ], $overrides);
    }

    public function test_creating_a_new_company_via_the_create_option_modal_persists_it_and_selects_it_on_the_form(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        Livewire::test(CreateCallRecord::class)
            ->mountFormComponentAction('prospect_id', 'createProspect')
            ->setFormComponentActionData($this->completeProspectData([
                'company_name' => 'Brand New Co',
                'contact_person' => 'Priya Nair',
            ]))
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
            ->setFormComponentActionData($this->completeProspectData(['company_name' => 'Another New Co']))
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
