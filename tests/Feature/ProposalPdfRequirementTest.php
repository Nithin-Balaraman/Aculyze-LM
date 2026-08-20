<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Enums\ProposalStage;
use App\Filament\Resources\ProposalResource\Pages\CreateProposal;
use App\Filament\Resources\ProposalResource\Pages\EditProposal;
use App\Filament\Resources\ProposalResource\Pages\ViewProposal;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A PDF becomes mandatory the moment a Proposal's Stage is "Proposal Sent"
 * (App\Enums\ProposalStage::Sent) — on Create or Edit, strictly on every
 * save, including an already-Sent Proposal from before this field existed
 * (deliberate: no backfill/grandfathering, see the investigation this
 * feature was built from). Stored on the 'local' disk (private, already
 * signature-protected by Laravel's own storage.local route — see
 * config/filesystems.php), not the public 'avatars' disk, and viewed via
 * ProposalResource::downloadPdfAction() rather than a direct URL.
 */
class ProposalPdfRequirementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function validatedLead(User $owner): Lead
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);

        return Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'stage' => LeadStage::Validated,
            'temperature' => 'hot',
            'notes' => 'Validated in test fixture.',
        ]);
    }

    private function proposal(User $owner, ProposalStage $stage, ?string $pdfPath = null): Proposal
    {
        $lead = $this->validatedLead($owner);

        return Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $lead->prospect_id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'stage' => $stage,
            'pdf_path' => $pdfPath,
        ]);
    }

    public function test_creating_a_proposal_with_stage_sent_and_no_pdf_fails_validation(): void
    {
        $employee = User::factory()->create();
        $lead = $this->validatedLead($employee);

        $this->actingAs($employee);

        Livewire::test(CreateProposal::class)
            ->fillForm([
                'lead_id' => $lead->id,
                'stage' => ProposalStage::Sent->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['pdf_path' => 'required']);

        $this->assertDatabaseCount('proposals', 0);
    }

    public function test_creating_a_proposal_with_stage_sent_and_a_pdf_succeeds(): void
    {
        $employee = User::factory()->create();
        $lead = $this->validatedLead($employee);

        $this->actingAs($employee);

        $file = UploadedFile::fake()->create('proposal.pdf', 100, 'application/pdf');

        Livewire::test(CreateProposal::class)
            ->fillForm([
                'lead_id' => $lead->id,
                'stage' => ProposalStage::Sent->value,
                'pdf_path' => $file,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $proposal = Proposal::sole();
        $this->assertNotNull($proposal->pdf_path);
        Storage::disk('local')->assertExists($proposal->pdf_path);
    }

    public function test_creating_a_proposal_in_a_stage_other_than_sent_does_not_require_or_show_the_pdf_field(): void
    {
        $employee = User::factory()->create();
        $lead = $this->validatedLead($employee);

        $this->actingAs($employee);

        Livewire::test(CreateProposal::class)
            ->fillForm([
                'lead_id' => $lead->id,
                'stage' => ProposalStage::BeingPrepared->value,
            ])
            ->assertFormFieldIsHidden('pdf_path')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('proposals', 1);
    }

    public function test_editing_a_proposal_into_stage_sent_without_a_pdf_fails_validation(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposal($employee, ProposalStage::BeingPrepared);

        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->fillForm(['stage' => ProposalStage::Sent->value])
            ->call('save')
            ->assertHasFormErrors(['pdf_path' => 'required']);
    }

    public function test_editing_a_proposal_into_stage_sent_with_a_pdf_succeeds(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposal($employee, ProposalStage::BeingPrepared);

        $this->actingAs($employee);

        $file = UploadedFile::fake()->create('proposal.pdf', 100, 'application/pdf');

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->fillForm([
                'stage' => ProposalStage::Sent->value,
                'pdf_path' => $file,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $proposal->refresh();
        $this->assertNotNull($proposal->pdf_path);
        Storage::disk('local')->assertExists($proposal->pdf_path);
    }

    /**
     * Strict enforcement, deliberately with no grandfathering: a Proposal
     * that was already Sent before this field existed (pdf_path still
     * null) must have a PDF attached the next time it is opened and
     * saved — even if nothing else about the save changes.
     */
    public function test_saving_an_already_sent_proposal_without_a_pdf_fails_validation_even_unchanged(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposal($employee, ProposalStage::Sent, pdfPath: null);

        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->fillForm(['stage' => ProposalStage::Sent->value])
            ->call('save')
            ->assertHasFormErrors(['pdf_path' => 'required']);
    }

    public function test_editing_a_sent_proposal_that_already_has_a_pdf_does_not_require_reuploading(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposal($employee, ProposalStage::Sent, pdfPath: 'proposal-pdfs/existing.pdf');
        Storage::disk('local')->put('proposal-pdfs/existing.pdf', 'fake pdf contents');

        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->fillForm(['stage' => ProposalStage::Sent->value])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_moving_a_sent_proposal_back_to_another_stage_no_longer_requires_or_shows_the_pdf_field(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposal($employee, ProposalStage::Sent, pdfPath: 'proposal-pdfs/existing.pdf');
        Storage::disk('local')->put('proposal-pdfs/existing.pdf', 'fake pdf contents');

        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->fillForm(['stage' => ProposalStage::BeingPrepared->value])
            ->assertFormFieldIsHidden('pdf_path')
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_download_pdf_action_is_hidden_when_no_pdf_is_attached(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposal($employee, ProposalStage::BeingPrepared);

        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->assertActionHidden('downloadPdf');
    }

    public function test_owner_can_see_and_use_the_download_pdf_action(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposal($employee, ProposalStage::Sent, pdfPath: 'proposal-pdfs/existing.pdf');
        Storage::disk('local')->put('proposal-pdfs/existing.pdf', 'fake pdf contents');

        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->assertActionVisible('downloadPdf')
            ->callAction('downloadPdf')
            ->assertFileDownloaded();

        Livewire::test(ViewProposal::class, ['record' => $proposal->getRouteKey()])
            ->assertActionVisible('downloadPdf')
            ->callAction('downloadPdf')
            ->assertFileDownloaded();
    }

    public function test_admin_can_see_and_use_the_download_pdf_action_for_any_employees_proposal(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create();
        $proposal = $this->proposal($employee, ProposalStage::Sent, pdfPath: 'proposal-pdfs/existing.pdf');
        Storage::disk('local')->put('proposal-pdfs/existing.pdf', 'fake pdf contents');

        $this->actingAs($admin);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->assertActionVisible('downloadPdf')
            ->callAction('downloadPdf')
            ->assertFileDownloaded();
    }

    /**
     * A different employee can't even reach this Proposal's Edit/View page
     * at all — ProposalResource::getEloquentQuery()'s visibleTo() scoping
     * means the record simply isn't found for them (a 404, same as every
     * other resource in this app), so there's no separate authorization
     * check needed for the download action itself.
     */
    public function test_a_different_employee_cannot_reach_the_proposal_at_all(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $proposal = $this->proposal($owner, ProposalStage::Sent, pdfPath: 'proposal-pdfs/existing.pdf');
        Storage::disk('local')->put('proposal-pdfs/existing.pdf', 'fake pdf contents');

        $this->actingAs($intruder);

        $this->get(\App\Filament\Resources\ProposalResource::getUrl('edit', ['record' => $proposal]))->assertNotFound();
        $this->get(\App\Filament\Resources\ProposalResource::getUrl('view', ['record' => $proposal]))->assertNotFound();
    }
}
