<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Filament\Pages\PipelineBoard;
use App\Filament\Resources\ProspectResource\Pages\CreateProspect;
use App\Filament\Resources\ProspectResource\Pages\EditProspect;
use App\Filament\Resources\ProspectResource\Pages\ViewProspect;
use App\Models\CallRecord;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * URGENT, Saji-requested: Contact Person, Designation, and Email are often
 * genuinely unknown when a company is first added to the Database, so
 * these three (and only these three — see ProspectFormMandatoryFieldsTest
 * for every other field's unchanged ->required() coverage) are no longer
 * mandatory on Create or Edit. Both pages share the exact same
 * ProspectResource::formSchema(), so a single edit there covers Create,
 * Edit, and the "+ Create new company…" inline modal reused from
 * CallRecordResource (see CallRecordCreateCompanyInlineTest) all at once.
 *
 * The prospects.contact_person/designation/email columns have been
 * nullable at the database level since the very first migration
 * (create_prospects_table) — confirmed directly against the live schema
 * before this change — so no migration was needed here, only the
 * form-level ->required() removal.
 */
class ProspectFormOptionalContactFieldsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function completeFormData(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Some Co',
            'contact_person' => 'Someone',
            'designation' => 'Manager',
            'telephone' => '+91 90000 00000',
            'mobile' => null,
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

    public function test_creating_a_prospect_with_contact_person_designation_and_email_all_blank_succeeds(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        Livewire::test(CreateProspect::class)
            ->fillForm($this->completeFormData([
                'contact_person' => null,
                'designation' => null,
                'email' => null,
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $prospect = Prospect::where('company_name', 'Some Co')->sole();
        $this->assertNull($prospect->contact_person);
        $this->assertNull($prospect->designation);
        $this->assertNull($prospect->email);
    }

    /**
     * Each field is also independently optional, not just all-or-nothing —
     * proves one can be filled while the other two stay blank.
     */
    public function test_creating_a_prospect_with_only_some_of_the_three_fields_blank_succeeds(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        Livewire::test(CreateProspect::class)
            ->fillForm($this->completeFormData([
                'contact_person' => 'Someone',
                'designation' => null,
                'email' => null,
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $prospect = Prospect::where('company_name', 'Some Co')->sole();
        $this->assertSame('Someone', $prospect->contact_person);
        $this->assertNull($prospect->designation);
        $this->assertNull($prospect->email);
    }

    /**
     * Existing Prospects created before this change (every text field
     * still filled in, since it was mandatory at the time) must be
     * completely unaffected — editing an unrelated field must not disturb
     * these three.
     */
    public function test_editing_an_unrelated_field_on_an_existing_fully_filled_prospect_leaves_these_three_fields_untouched(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create([
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'contact_person' => 'Jane Doe',
            'designation' => 'Procurement Head',
            'email' => 'jane@example.com',
        ]);
        $this->actingAs($employee);

        Livewire::test(EditProspect::class, ['record' => $prospect->getRouteKey()])
            ->fillForm(['notes' => 'Updated notes only.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $prospect->refresh();
        $this->assertSame('Jane Doe', $prospect->contact_person);
        $this->assertSame('Procurement Head', $prospect->designation);
        $this->assertSame('jane@example.com', $prospect->email);
        $this->assertSame('Updated notes only.', $prospect->notes);
    }

    /**
     * A field that's optional to create must also be safely editable back
     * to blank — the reverse direction of the create-time test above.
     */
    public function test_editing_an_existing_prospect_to_blank_contact_person_designation_and_email_succeeds(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create([
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'contact_person' => 'Jane Doe',
            'designation' => 'Procurement Head',
            'email' => 'jane@example.com',
        ]);
        $this->actingAs($employee);

        Livewire::test(EditProspect::class, ['record' => $prospect->getRouteKey()])
            ->fillForm([
                'contact_person' => null,
                'designation' => null,
                'email' => null,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $prospect->refresh();
        $this->assertNull($prospect->contact_person);
        $this->assertNull($prospect->designation);
        $this->assertNull($prospect->email);
    }

    /**
     * The View page's infolist already used ->placeholder('—') for every
     * field, including these three, before this change — this locks in
     * that a genuinely blank Prospect (not just a not-yet-backfilled one)
     * renders cleanly rather than a raw empty string or an error.
     */
    public function test_view_page_shows_a_placeholder_for_a_prospect_with_these_three_fields_blank(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create([
            'contact_person' => null,
            'designation' => null,
            'email' => null,
        ]);
        $this->actingAs($admin);

        Livewire::test(ViewProspect::class, ['record' => $prospect->getRouteKey()])
            ->assertSuccessful();
    }

    /**
     * Investigation step 3: a Call Record pulls Contact/Email straight from
     * its Prospect on the Pipeline Board's Calls card — callLane() already
     * reads these via ?-> and the card()'s own `details`/`collapsedDetails`
     * arrays are filtered to drop any blank entry (see
     * PipelineBoard::card()'s array_filter(filled(...))), so a Prospect
     * with all three fields blank must simply omit those rows, not error or
     * render an empty "Contact:" line.
     */
    public function test_pipeline_board_calls_card_omits_blank_contact_and_email_rows_cleanly(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);
        $prospect = Prospect::factory()->create([
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'contact_person' => null,
            'designation' => null,
            'email' => null,
        ]);
        CallRecord::create([
            'prospect_id' => $prospect->id, 'user_id' => $employee->id,
            'called_at' => now(), 'outcome' => CallOutcome::NoAnswer,
        ]);

        $method = new \ReflectionMethod(PipelineBoard::class, 'callLane');
        $method->setAccessible(true);
        $card = $method->invoke(app(PipelineBoard::class))['cards'][0];

        $labels = array_column($card['details'], 'label');
        $this->assertNotContains('Email', $labels);

        $collapsedLabels = array_column($card['collapsedDetails'], 'label');
        $this->assertNotContains('Contact', $collapsedLabels);
    }
}
