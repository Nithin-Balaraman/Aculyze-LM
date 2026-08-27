<?php

namespace Tests\Feature;

use App\Enums\AppointmentStage;
use App\Enums\CallOutcome;
use App\Enums\FollowUpStatus;
use App\Enums\LeadStage;
use App\Enums\ProposalStage;
use App\Enums\UserRole;
use App\Filament\Resources\AppointmentResource;
use App\Filament\Resources\AppointmentResource\Pages\EditAppointment;
use App\Filament\Resources\CallRecordResource;
use App\Filament\Resources\CallRecordResource\Pages\EditCallRecord;
use App\Filament\Resources\FollowUpResource;
use App\Filament\Resources\FollowUpResource\Pages\EditFollowUp;
use App\Filament\Resources\LeadResource;
use App\Filament\Resources\LeadResource\Pages\EditLead;
use App\Filament\Resources\ProposalResource;
use App\Filament\Resources\ProposalResource\Pages\EditProposal;
use App\Filament\Resources\ProspectResource;
use App\Filament\Resources\ProspectResource\Pages\EditProspect;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\Appointment;
use App\Models\CallRecord;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Filament's default EditRecord::getRedirectUrl() is null, so save() never
 * calls redirect() at all — without an override, "Save" left the user
 * sitting on the same Edit page instead of back on the list they came from
 * (the same place "Cancel" already goes). Each Edit*.php page now overrides
 * getRedirectUrl() to always return the index URL — mirrors the identical
 * fix already applied to the Create*.php pages
 * (see CreateRecordRedirectsToIndexTest).
 */
class EditRecordRedirectsToIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_a_lead_redirects_to_the_index(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => LeadStage::Validated,
            'temperature' => 'hot',
            'notes' => 'Validated in test fixture.',
        ]);

        $this->actingAs($employee);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(LeadResource::getUrl('index'));
    }

    public function test_editing_an_appointment_redirects_to_the_index(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $appointment = Appointment::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'appointment_at' => now()->addDay(),
            'stage' => AppointmentStage::AppointmentMade,
        ]);

        $this->actingAs($employee);

        Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(AppointmentResource::getUrl('index'));
    }

    public function test_editing_a_proposal_redirects_to_the_index(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => LeadStage::Validated,
            'temperature' => 'hot',
            'notes' => 'Validated in test fixture.',
        ]);
        $proposal = Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => ProposalStage::Sent,
            'value' => 450000,
            'sent_at' => now()->subDays(2),
            'notes' => 'Proposal fixture.',
            // A Sent Proposal requires at least one attachment on every
            // save (strict, no grandfathering — see
            // ProposalAttachmentRequirementTest) — unrelated to what this
            // test actually covers (the redirect), so the fixture just
            // already has one. The file must actually exist on the (faked)
            // disk, or Filament's FileUpload hydration treats it as
            // missing and required() fails anyway.
            'attachment_paths' => ['proposal-pdfs/existing.pdf'],
            'attachment_names' => ['proposal-pdfs/existing.pdf' => 'existing.pdf'],
        ]);
        Storage::fake('local')->put('proposal-pdfs/existing.pdf', 'fake pdf contents');

        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(ProposalResource::getUrl('index'));
    }

    public function test_editing_a_follow_up_redirects_to_the_index(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $followUp = FollowUp::create([
            'prospect_id' => $prospect->id,
            'user_id' => $employee->id,
            'follow_up_at' => now()->addDay(),
            'reason' => 'Callback later',
            'status' => FollowUpStatus::Pending,
        ]);

        $this->actingAs($employee);

        Livewire::test(EditFollowUp::class, ['record' => $followUp->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(FollowUpResource::getUrl('index'));
    }

    public function test_editing_a_call_record_redirects_to_the_index(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $employee->id,
            'called_at' => now(),
            'outcome' => CallOutcome::NoAnswer,
            'follow_up_at' => now()->addDay(),
        ]);

        $this->actingAs($employee);

        Livewire::test(EditCallRecord::class, ['record' => $call->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(CallRecordResource::getUrl('index'));
    }

    public function test_editing_a_prospect_redirects_to_the_index(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);

        $this->actingAs($employee);

        Livewire::test(EditProspect::class, ['record' => $prospect->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(ProspectResource::getUrl('index'));
    }

    public function test_editing_a_user_redirects_to_the_index(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create(['role' => UserRole::Employee]);

        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $employee->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(UserResource::getUrl('index'));
    }
}
