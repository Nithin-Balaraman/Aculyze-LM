<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Enums\ProposalStage;
use App\Filament\Resources\ProposalResource\Pages\EditProposal;
use App\Filament\Resources\ProposalResource\Pages\ViewProposal;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Proposal's own database ID (no separate reference system) is shown
 * as the page subheading on Edit and View, so it's visible before ever
 * downloading anything attached to it.
 *
 * This file previously also covered the downloaded filename synthesis
 * ("{Company Name} - {Proposal ID}.pdf", with company-name sanitization)
 * from the single-pdf_path era. That synthesis no longer runs for new
 * uploads at all — every attachment now downloads under its own real
 * original filename (see Proposal::attachments(), populated via
 * ProposalResource::formSchema()'s ->storeFileNamesIn()) — so those tests
 * no longer describe real app behavior and were retired rather than
 * rewritten. The one place that logic still exists is the
 * attachment_paths/attachment_names migration's own backfill for
 * Proposals that already had a pdf_path before this change (see
 * database/migrations/2026_08_27_..._replace_pdf_path_with_attachments_
 * on_proposals_table.php's up() method, which duplicates the exact same
 * sanitization inline rather than calling into app code a migration must
 * keep working correctly independent of); it was verified directly
 * against real data by rolling the migration back and forward again
 * rather than by an automated test here, since exercising a schema
 * migration's own up()/down() inside a RefreshDatabase-wrapped test would
 * risk an implicit commit from the DDL breaking that test's transaction
 * isolation for every test after it.
 */
class ProposalAttachmentReferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function proposalFor(User $owner, string $companyName): Proposal
    {
        $prospect = Prospect::factory()->create(['company_name' => $companyName, 'assigned_to' => $owner->id, 'created_by' => $owner->id]);
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'stage' => LeadStage::Validated,
            'temperature' => 'hot',
            'notes' => 'Validated in test fixture.',
        ]);

        $proposal = Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'stage' => ProposalStage::Sent,
            'attachment_paths' => ['proposal-attachments/existing.pdf'],
            'attachment_names' => ['proposal-attachments/existing.pdf' => 'existing.pdf'],
        ]);
        Storage::disk('local')->put('proposal-attachments/existing.pdf', 'fake pdf contents');

        return $proposal;
    }

    public function test_the_proposal_id_is_displayed_on_the_edit_page(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposalFor($employee, 'Chennai Precision Engineering Works');

        $this->actingAs($employee);

        $test = Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()]);

        $this->assertSame("Proposal #{$proposal->id}", $test->instance()->getSubheading());
        $test->assertSee("Proposal #{$proposal->id}");
    }

    public function test_the_proposal_id_is_displayed_on_the_view_page(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposalFor($employee, 'Chennai Precision Engineering Works');

        $this->actingAs($employee);

        $test = Livewire::test(ViewProposal::class, ['record' => $proposal->getRouteKey()]);

        $this->assertSame("Proposal #{$proposal->id}", $test->instance()->getSubheading());
        $test->assertSee("Proposal #{$proposal->id}");
    }
}
