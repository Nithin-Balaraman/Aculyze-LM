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
 * At least one attachment becomes mandatory the moment a Proposal's Stage
 * is "Proposal Sent" (App\Enums\ProposalStage::Sent) — on Create or Edit,
 * strictly on every save, including an already-Sent Proposal from before
 * this field existed (deliberate: no backfill/grandfathering, see the
 * investigation this feature was built from). A Proposal can carry any
 * number of attachments, of any file type (not just PDF, as it once was) —
 * stored on the 'local' disk (private, already signature-protected by
 * Laravel's own storage.local route — see config/filesystems.php), not the
 * public 'avatars' disk, and viewed via
 * ProposalResource::downloadAttachmentAction() rather than a direct URL.
 */
class ProposalAttachmentRequirementTest extends TestCase
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

    /**
     * @param  array<int, string>|null  $attachmentPaths
     */
    private function proposal(User $owner, ProposalStage $stage, ?array $attachmentPaths = null): Proposal
    {
        $lead = $this->validatedLead($owner);

        return Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $lead->prospect_id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'stage' => $stage,
            'attachment_paths' => $attachmentPaths,
            'attachment_names' => $attachmentPaths === null ? null : collect($attachmentPaths)->mapWithKeys(fn (string $path) => [$path => basename($path)])->all(),
        ]);
    }

    public function test_creating_a_proposal_with_stage_sent_and_no_attachments_fails_validation(): void
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
            ->assertHasFormErrors(['attachment_paths' => 'required']);

        $this->assertDatabaseCount('proposals', 0);
    }

    public function test_creating_a_proposal_with_stage_sent_and_an_attachment_succeeds(): void
    {
        $employee = User::factory()->create();
        $lead = $this->validatedLead($employee);

        $this->actingAs($employee);

        $file = UploadedFile::fake()->create('proposal.pdf', 100, 'application/pdf');

        Livewire::test(CreateProposal::class)
            ->fillForm([
                'lead_id' => $lead->id,
                'stage' => ProposalStage::Sent->value,
                'attachment_paths' => [$file],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $proposal = Proposal::sole();
        $this->assertNotEmpty($proposal->attachment_paths);
        Storage::disk('local')->assertExists($proposal->attachment_paths[0]);
        $this->assertSame('proposal.pdf', $proposal->attachments()[$proposal->attachment_paths[0]]);
    }

    /**
     * Any file type is accepted now, not just PDF — the acceptedFileTypes()
     * restriction that used to scope this field to application/pdf was
     * dropped when it became a multi-attachment field.
     */
    public function test_creating_a_proposal_with_stage_sent_and_a_non_pdf_attachment_succeeds(): void
    {
        $employee = User::factory()->create();
        $lead = $this->validatedLead($employee);

        $this->actingAs($employee);

        $file = UploadedFile::fake()->create('notes.docx', 50, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        Livewire::test(CreateProposal::class)
            ->fillForm([
                'lead_id' => $lead->id,
                'stage' => ProposalStage::Sent->value,
                'attachment_paths' => [$file],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $proposal = Proposal::sole();
        $this->assertSame('notes.docx', $proposal->attachments()[$proposal->attachment_paths[0]]);
    }

    public function test_creating_a_proposal_with_multiple_attachments_succeeds(): void
    {
        $employee = User::factory()->create();
        $lead = $this->validatedLead($employee);

        $this->actingAs($employee);

        $pdf = UploadedFile::fake()->create('quote.pdf', 100, 'application/pdf');
        $sheet = UploadedFile::fake()->create('pricing.xlsx', 60, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        Livewire::test(CreateProposal::class)
            ->fillForm([
                'lead_id' => $lead->id,
                'stage' => ProposalStage::Sent->value,
                'attachment_paths' => [$pdf, $sheet],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $proposal = Proposal::sole();
        $this->assertCount(2, $proposal->attachment_paths);
        $names = array_values($proposal->attachments());
        $this->assertContains('quote.pdf', $names);
        $this->assertContains('pricing.xlsx', $names);

        foreach ($proposal->attachment_paths as $path) {
            Storage::disk('local')->assertExists($path);
        }
    }

    public function test_creating_a_proposal_in_a_stage_other_than_sent_does_not_require_or_show_the_attachments_field(): void
    {
        $employee = User::factory()->create();
        $lead = $this->validatedLead($employee);

        $this->actingAs($employee);

        Livewire::test(CreateProposal::class)
            ->fillForm([
                'lead_id' => $lead->id,
                'stage' => ProposalStage::BeingPrepared->value,
            ])
            ->assertFormFieldIsHidden('attachment_paths')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('proposals', 1);
    }

    public function test_editing_a_proposal_into_stage_sent_without_an_attachment_fails_validation(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposal($employee, ProposalStage::BeingPrepared);

        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->fillForm(['stage' => ProposalStage::Sent->value])
            ->call('save')
            ->assertHasFormErrors(['attachment_paths' => 'required']);
    }

    public function test_editing_a_proposal_into_stage_sent_with_an_attachment_succeeds(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposal($employee, ProposalStage::BeingPrepared);

        $this->actingAs($employee);

        $file = UploadedFile::fake()->create('proposal.pdf', 100, 'application/pdf');

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->fillForm([
                'stage' => ProposalStage::Sent->value,
                'attachment_paths' => [$file],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $proposal->refresh();
        $this->assertNotEmpty($proposal->attachment_paths);
        Storage::disk('local')->assertExists($proposal->attachment_paths[0]);
    }

    /**
     * Strict enforcement, deliberately with no grandfathering: a Proposal
     * that was already Sent before this field existed (attachment_paths
     * still null) must have an attachment the next time it is opened and
     * saved — even if nothing else about the save changes.
     */
    public function test_saving_an_already_sent_proposal_without_an_attachment_fails_validation_even_unchanged(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposal($employee, ProposalStage::Sent, attachmentPaths: null);

        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->fillForm(['stage' => ProposalStage::Sent->value])
            ->call('save')
            ->assertHasFormErrors(['attachment_paths' => 'required']);
    }

    public function test_editing_a_sent_proposal_that_already_has_an_attachment_does_not_require_reuploading(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposal($employee, ProposalStage::Sent, attachmentPaths: ['proposal-attachments/existing.pdf']);
        Storage::disk('local')->put('proposal-attachments/existing.pdf', 'fake pdf contents');

        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->fillForm(['stage' => ProposalStage::Sent->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $proposal->refresh();
        $this->assertSame(['proposal-attachments/existing.pdf'], $proposal->attachment_paths);
        Storage::disk('local')->assertExists('proposal-attachments/existing.pdf');
    }

    /**
     * Filament's FileUpload doesn't dehydrate a hidden field by default
     * (isDehydratedWhenHidden() is false unless opted in) — and this
     * field's visible() depends on attachment_paths' own value, so clearing
     * every file while also moving stage away from Sent makes the field
     * hidden in that very save. Without ->dehydratedWhenHidden(), the
     * cleared state would never overwrite the model, silently leaving the
     * stale paths (and the "Download" action) behind — this is the bug
     * this test guards against.
     */
    public function test_removing_an_uploaded_attachment_and_saving_clears_attachment_paths_and_deletes_the_file(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposal($employee, ProposalStage::Sent, attachmentPaths: ['proposal-attachments/existing.pdf']);
        Storage::disk('local')->put('proposal-attachments/existing.pdf', 'fake pdf contents');

        $this->actingAs($employee);

        $test = Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()]);

        // Mirrors the actual "x" button, which calls
        // $wire.deleteUploadedFile(statePath, fileKey) — the file is
        // deleted from storage immediately on click, independent of
        // whether the form is ever saved.
        $fileKey = array_key_first($test->get('data.attachment_paths'));
        $test->call('deleteUploadedFile', 'data.attachment_paths', $fileKey);
        Storage::disk('local')->assertMissing('proposal-attachments/existing.pdf');

        $test->fillForm(['stage' => ProposalStage::BeingPrepared->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $proposal->refresh();
        $this->assertEmpty($proposal->attachment_paths);
    }

    public function test_the_download_action_is_hidden_after_the_only_attachment_is_removed_and_saved(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposal($employee, ProposalStage::Sent, attachmentPaths: ['proposal-attachments/existing.pdf']);
        Storage::disk('local')->put('proposal-attachments/existing.pdf', 'fake pdf contents');

        $this->actingAs($employee);

        $test = Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()]);
        $fileKey = array_key_first($test->get('data.attachment_paths'));
        $test->call('deleteUploadedFile', 'data.attachment_paths', $fileKey)
            ->fillForm(['stage' => ProposalStage::BeingPrepared->value])
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->assertActionHidden('downloadAttachment');
        Livewire::test(ViewProposal::class, ['record' => $proposal->getRouteKey()])
            ->assertActionHidden('downloadAttachment');
    }

    /**
     * Removing the only attachment while stage stays Sent must still block
     * the save — the field remains visible in that case (stage alone
     * already keeps it so), and required() correctly has nothing to
     * dehydrate around.
     */
    public function test_removing_the_only_attachment_while_stage_stays_sent_still_fails_validation(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposal($employee, ProposalStage::Sent, attachmentPaths: ['proposal-attachments/existing.pdf']);
        Storage::disk('local')->put('proposal-attachments/existing.pdf', 'fake pdf contents');

        $this->actingAs($employee);

        $test = Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()]);
        $fileKey = array_key_first($test->get('data.attachment_paths'));

        $test->call('deleteUploadedFile', 'data.attachment_paths', $fileKey)
            ->call('save')
            ->assertHasFormErrors(['attachment_paths' => 'required']);
    }

    public function test_moving_a_sent_proposal_back_to_another_stage_no_longer_requires_the_attachments_field(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposal($employee, ProposalStage::Sent, attachmentPaths: ['proposal-attachments/existing.pdf']);
        Storage::disk('local')->put('proposal-attachments/existing.pdf', 'fake pdf contents');

        $this->actingAs($employee);

        // required() still keys only off stage === Sent — moving away from
        // Sent drops the requirement even though the field itself stays
        // visible (see the visibility test below), and saving without
        // touching attachment_paths at all must not fail.
        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->fillForm(['stage' => ProposalStage::BeingPrepared->value])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    /**
     * Follow-up fix: the field previously disappeared entirely once stage
     * moved on from Sent, even though a file was still attached and still
     * downloadable via downloadAttachmentAction() — now visible() also
     * accounts for an existing upload, so it stays visible/reviewable
     * regardless of which stage the record later moves to.
     */
    public function test_a_proposal_with_an_existing_attachment_still_shows_the_field_after_moving_to_a_later_stage(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposal($employee, ProposalStage::Sent, attachmentPaths: ['proposal-attachments/existing.pdf']);
        Storage::disk('local')->put('proposal-attachments/existing.pdf', 'fake pdf contents');

        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->fillForm(['stage' => ProposalStage::CustomerAccepted->value])
            ->assertFormFieldIsVisible('attachment_paths')
            ->call('save')
            ->assertHasNoFormErrors();
    }

    /**
     * Unchanged regression guard: a Proposal with no attachment at all, in
     * a non-Sent stage, still hides the field exactly as before — the
     * field only appears once EITHER condition (Sent, or an existing
     * upload) is true, not simply because a non-Sent stage was touched.
     */
    public function test_a_proposal_with_no_attachment_in_a_non_sent_stage_still_hides_the_field(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposal($employee, ProposalStage::BeingPrepared, attachmentPaths: null);

        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->assertFormFieldIsHidden('attachment_paths')
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_download_action_is_hidden_when_no_attachment_exists(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposal($employee, ProposalStage::BeingPrepared);

        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->assertActionHidden('downloadAttachment');
    }

    /**
     * Exactly one attachment: the picker form is empty, so Filament runs
     * the action immediately with no modal at all — the common case stays
     * a single click, exactly like the old single-PDF action was.
     */
    public function test_owner_can_see_and_use_the_download_action_with_a_single_attachment(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposal($employee, ProposalStage::Sent, attachmentPaths: ['proposal-attachments/existing.pdf']);
        Storage::disk('local')->put('proposal-attachments/existing.pdf', 'fake pdf contents');

        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->assertActionVisible('downloadAttachment')
            ->callAction('downloadAttachment')
            ->assertFileDownloaded();

        Livewire::test(ViewProposal::class, ['record' => $proposal->getRouteKey()])
            ->assertActionVisible('downloadAttachment')
            ->callAction('downloadAttachment')
            ->assertFileDownloaded();
    }

    /**
     * More than one attachment: the action's own form asks which file via
     * a Select keyed by stored path, labeled with each file's own real
     * name.
     */
    public function test_owner_can_pick_which_attachment_to_download_when_there_are_several(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposal($employee, ProposalStage::Sent, attachmentPaths: [
            'proposal-attachments/quote.pdf',
            'proposal-attachments/pricing.xlsx',
        ]);
        Storage::disk('local')->put('proposal-attachments/quote.pdf', 'fake pdf contents');
        Storage::disk('local')->put('proposal-attachments/pricing.xlsx', 'fake xlsx contents');

        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->assertActionVisible('downloadAttachment')
            ->mountAction('downloadAttachment')
            ->assertFormFieldExists('path', 'mountedActionForm')
            ->setActionData(['path' => 'proposal-attachments/pricing.xlsx'])
            ->callMountedAction()
            ->assertFileDownloaded('pricing.xlsx');
    }

    public function test_admin_can_see_and_use_the_download_action_for_any_employees_proposal(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create();
        $proposal = $this->proposal($employee, ProposalStage::Sent, attachmentPaths: ['proposal-attachments/existing.pdf']);
        Storage::disk('local')->put('proposal-attachments/existing.pdf', 'fake pdf contents');

        $this->actingAs($admin);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->assertActionVisible('downloadAttachment')
            ->callAction('downloadAttachment')
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
        $proposal = $this->proposal($owner, ProposalStage::Sent, attachmentPaths: ['proposal-attachments/existing.pdf']);
        Storage::disk('local')->put('proposal-attachments/existing.pdf', 'fake pdf contents');

        $this->actingAs($intruder);

        $this->get(\App\Filament\Resources\ProposalResource::getUrl('edit', ['record' => $proposal]))->assertNotFound();
        $this->get(\App\Filament\Resources\ProposalResource::getUrl('view', ['record' => $proposal]))->assertNotFound();
    }
}
