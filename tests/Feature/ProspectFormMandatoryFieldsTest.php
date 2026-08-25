<?php

namespace Tests\Feature;

use App\Filament\Resources\ProspectResource\Pages\CreateProspect;
use App\Filament\Resources\ProspectResource\Pages\EditProspect;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Saji wants complete data captured on every Prospect record — every text
 * field on the Create/Edit form is mandatory, except Telephone/Mobile,
 * which only need at least one of the two filled (not both individually
 * required). This is a Filament FORM-level rule only — the bulk Excel
 * import (App\Filament\Pages\ImportProspects) is a separate, standalone
 * Livewire flow that calls Prospect::create() directly and never touches
 * ProspectResource::form()/formSchema() at all, and Prospect itself has no
 * model-level saving() guard — confirmed before implementing, so import
 * rows that are missing some of these fields keep working exactly as
 * before (import already has its own, much narrower rule: Company Name is
 * the only thing it refuses to import without).
 */
class ProspectFormMandatoryFieldsTest extends TestCase
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

    /**
     * @return array<int, array<int, string>>
     */
    public static function textFieldProvider(): array
    {
        return [
            ['contact_person'],
            ['designation'],
            ['email'],
            ['website'],
            ['industry'],
            ['source'],
            ['address'],
            ['locality'],
            ['city'],
            ['state'],
            ['pincode'],
            ['notes'],
        ];
    }

    #[DataProvider('textFieldProvider')]
    public function test_creating_a_prospect_without_a_required_text_field_fails_validation(string $field): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        Livewire::test(CreateProspect::class)
            ->fillForm($this->completeFormData([$field => null]))
            ->call('create')
            ->assertHasFormErrors([$field => 'required']);

        $this->assertDatabaseCount('prospects', 0);
    }

    public function test_creating_a_prospect_with_every_field_filled_succeeds(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        Livewire::test(CreateProspect::class)
            ->fillForm($this->completeFormData())
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('prospects', 1);
    }

    public function test_telephone_and_mobile_are_not_individually_required(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        Livewire::test(CreateProspect::class)
            ->fillForm($this->completeFormData(['telephone' => '+91 90000 00000', 'mobile' => null]))
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreateProspect::class)
            ->fillForm($this->completeFormData([
                'company_name' => 'Some Other Co',
                'telephone' => null,
                'mobile' => '+91 98765 43210',
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('prospects', 2);
    }

    public function test_creating_a_prospect_with_neither_telephone_nor_mobile_fails_validation(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        Livewire::test(CreateProspect::class)
            ->fillForm($this->completeFormData(['telephone' => null, 'mobile' => null]))
            ->call('create')
            ->assertHasFormErrors(['telephone' => 'required', 'mobile' => 'required']);

        $this->assertDatabaseCount('prospects', 0);
    }

    public function test_editing_an_existing_prospect_to_blank_a_required_field_fails_validation(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $this->actingAs($employee);

        Livewire::test(EditProspect::class, ['record' => $prospect->getRouteKey()])
            ->fillForm(['city' => null])
            ->call('save')
            ->assertHasFormErrors(['city' => 'required']);
    }

    public function test_editing_an_existing_prospect_to_blank_both_phone_fields_fails_validation(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $this->actingAs($employee);

        Livewire::test(EditProspect::class, ['record' => $prospect->getRouteKey()])
            ->fillForm(['telephone' => null, 'mobile' => null])
            ->call('save')
            ->assertHasFormErrors(['telephone' => 'required', 'mobile' => 'required']);
    }
}
