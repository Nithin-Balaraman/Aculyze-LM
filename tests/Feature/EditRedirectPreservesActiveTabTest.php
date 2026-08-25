<?php

namespace Tests\Feature;

use App\Enums\AppointmentStage;
use App\Enums\FollowUpStatus;
use App\Enums\LeadStage;
use App\Enums\ProposalOutcome;
use App\Enums\ProposalStage;
use App\Filament\Resources\AppointmentResource;
use App\Filament\Resources\AppointmentResource\Pages\CreateAppointment;
use App\Filament\Resources\AppointmentResource\Pages\EditAppointment;
use App\Filament\Resources\FollowUpResource;
use App\Filament\Resources\FollowUpResource\Pages\CreateFollowUp;
use App\Filament\Resources\FollowUpResource\Pages\EditFollowUp;
use App\Filament\Resources\LeadResource;
use App\Filament\Resources\LeadResource\Pages\CreateLead;
use App\Filament\Resources\LeadResource\Pages\EditLead;
use App\Filament\Resources\ProposalResource;
use App\Filament\Resources\ProposalResource\Pages\CreateProposal;
use App\Filament\Resources\ProposalResource\Pages\EditProposal;
use App\Models\Appointment;
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
 * Follow-up to the Create/Edit redirect fix: the 4 tabbed resources
 * (Follow-Ups, Appointments, Leads, Proposals) always redirected back to
 * the default (Pending) tab after Edit, even if the user edited a record
 * from History or Lost — because the row's Edit link never carried the
 * current tab forward, and getRedirectUrl() never read it back.
 *
 * Fix: EditAction::make()->url() on each resource's table now appends
 * ?activeTab=... (from the table's own activeTab), and each Edit*.php page
 * has a matching #[Url] $activeTab property that getRedirectUrl() reattaches
 * to the index URL. A direct/bookmarked edit URL with no activeTab param
 * still falls back to today's plain-index (default tab) behavior.
 *
 * Create is deliberately NOT touched — see the last test below. A newly
 * created record is never a match for the History/Lost tab filters (it's
 * always Pending-shaped), so redirecting Create back to a non-Pending
 * origin tab would just show an empty-feeling result.
 */
class EditRedirectPreservesActiveTabTest extends TestCase
{
    use RefreshDatabase;

    // --- Follow-Ups ---

    public function test_editing_a_follow_up_from_the_pending_tab_redirects_to_pending(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $followUp = FollowUp::create([
            'prospect_id' => $prospect->id,
            'user_id' => $employee->id,
            'follow_up_at' => now()->addDay(),
            'reason' => 'Callback',
            'status' => FollowUpStatus::Pending,
        ]);

        $this->actingAs($employee);

        Livewire::test(EditFollowUp::class, ['record' => $followUp->getRouteKey(), 'activeTab' => 'pending'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(FollowUpResource::getUrl('index', ['activeTab' => 'pending']));
    }

    public function test_editing_a_follow_up_from_the_history_tab_redirects_to_history(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $followUp = FollowUp::create([
            'prospect_id' => $prospect->id,
            'user_id' => $employee->id,
            'follow_up_at' => now()->subDay(),
            'reason' => 'Callback',
            'status' => FollowUpStatus::Cancelled,
        ]);

        $this->actingAs($employee);

        Livewire::test(EditFollowUp::class, ['record' => $followUp->getRouteKey(), 'activeTab' => 'history'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(FollowUpResource::getUrl('index', ['activeTab' => 'history']));
    }

    public function test_editing_a_follow_up_from_the_lost_tab_redirects_to_lost(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $followUp = FollowUp::create([
            'prospect_id' => $prospect->id,
            'user_id' => $employee->id,
            'follow_up_at' => now()->subDay(),
            'reason' => 'Callback',
            'status' => FollowUpStatus::Cancelled,
        ]);

        $this->actingAs($employee);

        Livewire::test(EditFollowUp::class, ['record' => $followUp->getRouteKey(), 'activeTab' => 'lost'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(FollowUpResource::getUrl('index', ['activeTab' => 'lost']));
    }

    public function test_editing_a_follow_up_via_a_direct_url_with_no_active_tab_falls_back_to_plain_index(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $followUp = FollowUp::create([
            'prospect_id' => $prospect->id,
            'user_id' => $employee->id,
            'follow_up_at' => now()->addDay(),
            'reason' => 'Callback',
            'status' => FollowUpStatus::Pending,
        ]);

        $this->actingAs($employee);

        Livewire::test(EditFollowUp::class, ['record' => $followUp->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(FollowUpResource::getUrl('index'));
    }

    // --- Appointments ---

    public function test_editing_an_appointment_from_the_pending_tab_redirects_to_pending(): void
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

        Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey(), 'activeTab' => 'pending'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(AppointmentResource::getUrl('index', ['activeTab' => 'pending']));
    }

    public function test_editing_an_appointment_from_the_history_tab_redirects_to_history(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $appointment = Appointment::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'appointment_at' => now()->subDay(),
            'stage' => AppointmentStage::AppointmentMade,
        ]);
        $appointment->markLost('Prospect went elsewhere.');

        $this->actingAs($employee);

        Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey(), 'activeTab' => 'history'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(AppointmentResource::getUrl('index', ['activeTab' => 'history']));
    }

    public function test_editing_an_appointment_from_the_lost_tab_redirects_to_lost(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $appointment = Appointment::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'appointment_at' => now()->subDay(),
            'stage' => AppointmentStage::AppointmentMade,
        ]);
        $appointment->markLost('Prospect went elsewhere.');

        $this->actingAs($employee);

        Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey(), 'activeTab' => 'lost'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(AppointmentResource::getUrl('index', ['activeTab' => 'lost']));
    }

    public function test_editing_an_appointment_via_a_direct_url_with_no_active_tab_falls_back_to_plain_index(): void
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

    // --- Leads ---

    public function test_editing_a_lead_from_the_pending_tab_redirects_to_pending(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => LeadStage::RequirementCollection,
            'temperature' => 'warm',
        ]);

        $this->actingAs($employee);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey(), 'activeTab' => 'pending'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(LeadResource::getUrl('index', ['activeTab' => 'pending']));
    }

    public function test_editing_a_lead_from_the_history_tab_redirects_to_history(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => LeadStage::RequirementCollection,
            'temperature' => 'warm',
        ]);
        $lead->markLost('Went with a competitor.');

        $this->actingAs($employee);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey(), 'activeTab' => 'history'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(LeadResource::getUrl('index', ['activeTab' => 'history']));
    }

    public function test_editing_a_lead_from_the_lost_tab_redirects_to_lost(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => LeadStage::RequirementCollection,
            'temperature' => 'warm',
        ]);
        $lead->markLost('Went with a competitor.');

        $this->actingAs($employee);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey(), 'activeTab' => 'lost'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(LeadResource::getUrl('index', ['activeTab' => 'lost']));
    }

    public function test_editing_a_lead_via_a_direct_url_with_no_active_tab_falls_back_to_plain_index(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => LeadStage::RequirementCollection,
            'temperature' => 'warm',
        ]);

        $this->actingAs($employee);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(LeadResource::getUrl('index'));
    }

    // --- Proposals ---

    private function makeValidatedLead(User $employee): Lead
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);

        return Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => LeadStage::Validated,
            'temperature' => 'hot',
            'notes' => 'Validated in test fixture.',
        ]);
    }

    public function test_editing_a_proposal_from_the_pending_tab_redirects_to_pending(): void
    {
        $employee = User::factory()->create();
        $lead = $this->makeValidatedLead($employee);
        $proposal = Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $lead->prospect_id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => ProposalStage::BeingPrepared,
        ]);

        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey(), 'activeTab' => 'pending'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(ProposalResource::getUrl('index', ['activeTab' => 'pending']));
    }

    public function test_editing_a_proposal_from_the_history_tab_redirects_to_history(): void
    {
        $employee = User::factory()->create();
        $lead = $this->makeValidatedLead($employee);
        $proposal = Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $lead->prospect_id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => ProposalStage::Sent,
            'outcome' => ProposalOutcome::Lost,
            'notes' => 'Went with a competitor.',
            // A Sent Proposal requires a PDF on every save (strict, no
            // grandfathering — see ProposalPdfRequirementTest) — unrelated
            // to what this test actually covers (the redirect), so the
            // fixture just already has one rather than exercising the
            // upload here. The file must actually exist on the (faked)
            // disk, or Filament's FileUpload hydration treats it as
            // missing and required() fails anyway.
            'pdf_path' => 'proposal-pdfs/existing.pdf',
        ]);
        Storage::fake('local')->put('proposal-pdfs/existing.pdf', 'fake pdf contents');

        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey(), 'activeTab' => 'history'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(ProposalResource::getUrl('index', ['activeTab' => 'history']));
    }

    public function test_editing_a_proposal_from_the_lost_tab_redirects_to_lost(): void
    {
        $employee = User::factory()->create();
        $lead = $this->makeValidatedLead($employee);
        $proposal = Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $lead->prospect_id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => ProposalStage::Sent,
            'outcome' => ProposalOutcome::Lost,
            'notes' => 'Went with a competitor.',
            // A Sent Proposal requires a PDF on every save (strict, no
            // grandfathering — see ProposalPdfRequirementTest) — unrelated
            // to what this test actually covers (the redirect), so the
            // fixture just already has one rather than exercising the
            // upload here. The file must actually exist on the (faked)
            // disk, or Filament's FileUpload hydration treats it as
            // missing and required() fails anyway.
            'pdf_path' => 'proposal-pdfs/existing.pdf',
        ]);
        Storage::fake('local')->put('proposal-pdfs/existing.pdf', 'fake pdf contents');

        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey(), 'activeTab' => 'lost'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(ProposalResource::getUrl('index', ['activeTab' => 'lost']));
    }

    public function test_editing_a_proposal_via_a_direct_url_with_no_active_tab_falls_back_to_plain_index(): void
    {
        $employee = User::factory()->create();
        $lead = $this->makeValidatedLead($employee);
        $proposal = Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $lead->prospect_id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => ProposalStage::BeingPrepared,
        ]);

        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(ProposalResource::getUrl('index'));
    }

    // --- Create is unaffected ---

    /**
     * Create's redirect must always land on the plain index (Pending tab)
     * regardless of which tab the user was on when they clicked Create —
     * a brand-new record is never a match for the History/Lost tab
     * filters, so honoring the origin tab here would just show an empty
     * result. Create*.php pages were deliberately left untouched by this
     * fix (no #[Url] $activeTab property, no reattachment) — this locks
     * that in.
     */
    public function test_create_redirect_is_unaffected_and_always_goes_to_the_plain_index(): void
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

        Livewire::test(CreateAppointment::class)
            ->fillForm([
                'prospect_id' => $prospect->id,
                'appointment_at' => now()->addDay(),
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(AppointmentResource::getUrl('index'));

        Livewire::test(CreateLead::class)
            ->fillForm(['prospect_id' => $prospect->id])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(LeadResource::getUrl('index'));

        $lead = $this->makeValidatedLead($employee);

        Livewire::test(CreateProposal::class)
            ->fillForm(['lead_id' => $lead->id])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(ProposalResource::getUrl('index'));
    }
}
