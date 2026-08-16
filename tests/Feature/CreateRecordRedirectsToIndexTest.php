<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Filament\Resources\AppointmentResource;
use App\Filament\Resources\AppointmentResource\Pages\CreateAppointment;
use App\Filament\Resources\CallRecordResource;
use App\Filament\Resources\CallRecordResource\Pages\CreateCallRecord;
use App\Filament\Resources\FollowUpResource;
use App\Filament\Resources\FollowUpResource\Pages\CreateFollowUp;
use App\Filament\Resources\LeadResource;
use App\Filament\Resources\LeadResource\Pages\CreateLead;
use App\Filament\Resources\ProposalResource;
use App\Filament\Resources\ProposalResource\Pages\CreateProposal;
use App\Filament\Resources\ProspectResource;
use App\Filament\Resources\ProspectResource\Pages\CreateProspect;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Models\Lead;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Filament's default CreateRecord::getRedirectUrl() prefers the new
 * record's view page, then its edit page, before falling back to the
 * resource's index — every resource here defines both view and edit pages,
 * so without an override, "Create" always left the user on the new
 * record's page instead of back on the list they started from (the same
 * place "Cancel" already goes). Each Create*.php page now overrides
 * getRedirectUrl() to always return the index URL.
 */
class CreateRecordRedirectsToIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_lead_redirects_to_the_index(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);

        $this->actingAs($employee);

        Livewire::test(CreateLead::class)
            ->fillForm(['prospect_id' => $prospect->id])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(LeadResource::getUrl('index'));
    }

    public function test_creating_an_appointment_redirects_to_the_index(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);

        $this->actingAs($employee);

        Livewire::test(CreateAppointment::class)
            ->fillForm([
                'prospect_id' => $prospect->id,
                'appointment_at' => now()->addDay(),
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(AppointmentResource::getUrl('index'));
    }

    public function test_creating_a_proposal_redirects_to_the_index(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => \App\Enums\LeadStage::Validated,
            'temperature' => 'hot',
            'notes' => 'Validated in test fixture.',
        ]);

        $this->actingAs($employee);

        Livewire::test(CreateProposal::class)
            ->fillForm(['lead_id' => $lead->id])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(ProposalResource::getUrl('index'));
    }

    public function test_creating_a_follow_up_redirects_to_the_index(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);

        $this->actingAs($employee);

        Livewire::test(CreateFollowUp::class)
            ->fillForm([
                'prospect_id' => $prospect->id,
                'follow_up_at' => now()->addDay(),
                'reason' => 'Callback later',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(FollowUpResource::getUrl('index'));
    }

    public function test_creating_a_call_record_redirects_to_the_index(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);

        $this->actingAs($employee);

        Livewire::test(CreateCallRecord::class)
            ->fillForm([
                'prospect_id' => $prospect->id,
                'outcome' => CallOutcome::NoAnswer->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(CallRecordResource::getUrl('index'));
    }

    public function test_creating_a_prospect_redirects_to_the_index(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee);

        Livewire::test(CreateProspect::class)
            ->fillForm(['company_name' => 'Brand New Co'])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(ProspectResource::getUrl('index'));
    }

    public function test_creating_a_user_redirects_to_the_index(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New Employee',
                'email' => 'new-employee@example.com',
                'password' => 'password',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(UserResource::getUrl('index'));
    }
}
