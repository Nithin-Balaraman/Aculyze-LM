<?php

namespace Tests\Feature;

use App\Filament\Resources\CallRecordResource\Pages\CreateCallRecord;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 2 item #5: once a company is selected on the Call Record form, its
 * saved Database details (Contact Person, Designation, Telephone, Mobile,
 * Email, Industry, Assigned To) render inline via the "Company Details"
 * section (see CallRecordResource::form() and
 * resources/views/filament/forms/prospect-call-details.blade.php).
 */
class CallRecordProspectDetailsPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_details_are_not_shown_before_a_company_is_selected(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['telephone' => '+91 90000 11111']);
        $this->actingAs($employee);

        Livewire::test(CreateCallRecord::class)
            ->assertDontSee($prospect->telephone);
    }

    public function test_company_details_render_the_selected_prospects_saved_fields(): void
    {
        $employee = User::factory()->create();
        $assignedEmployee = User::factory()->create(['name' => 'Ilaya Bharathi']);
        $prospect = Prospect::factory()->create([
            'contact_person' => 'Ravi Kumar',
            'designation' => 'Plant Manager',
            'telephone' => '+91 90000 22222',
            'mobile' => '+91 90000 33333',
            'email' => 'ravi@example.test',
            'industry' => 'Precision Engineering',
            'assigned_to' => $assignedEmployee->id,
        ]);
        $this->actingAs($employee);

        Livewire::test(CreateCallRecord::class)
            ->fillForm(['prospect_id' => $prospect->id])
            ->assertSee('Ravi Kumar')
            ->assertSee('Plant Manager')
            ->assertSee('+91 90000 22222')
            ->assertSee('+91 90000 33333')
            ->assertSee('ravi@example.test')
            ->assertSee('Precision Engineering')
            ->assertSee('Ilaya Bharathi');
    }

    public function test_company_details_update_when_a_different_company_is_selected(): void
    {
        $employee = User::factory()->create();
        $prospectA = Prospect::factory()->create(['telephone' => '+91 90000 44444']);
        $prospectB = Prospect::factory()->create(['telephone' => '+91 90000 55555']);
        $this->actingAs($employee);

        $test = Livewire::test(CreateCallRecord::class)
            ->fillForm(['prospect_id' => $prospectA->id])
            ->assertSee($prospectA->telephone)
            ->assertDontSee($prospectB->telephone);

        $test->fillForm(['prospect_id' => $prospectB->id])
            ->assertSee($prospectB->telephone)
            ->assertDontSee($prospectA->telephone);
    }

    public function test_missing_prospect_fields_render_as_a_dash_instead_of_blank_or_erroring(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create([
            'contact_person' => null,
            'designation' => null,
            'mobile' => null,
            'email' => null,
            'industry' => null,
        ]);
        $this->actingAs($employee);

        Livewire::test(CreateCallRecord::class)
            ->fillForm(['prospect_id' => $prospect->id])
            ->assertSee('—');
    }
}
