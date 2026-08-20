<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Enums\ProposalStage;
use App\Filament\Resources\ProposalResource\Pages\ListProposals;
use App\Filament\Widgets\ProspectProposalsTable;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Download PDF" surfaced as a quick-access row action in two more
 * places it wasn't previously visible: the main Proposals list
 * (ListProposals) and the Prospect View page's Proposals mini-table
 * (ProspectProposalsTable) — reusing
 * ProposalResource::downloadPdfTableAction(), which shares the exact
 * same visibility condition and download logic as the pre-existing
 * Edit/View header action (ProposalResource::downloadPdfAction()), just
 * wrapped in Filament\Tables\Actions\Action instead of
 * Filament\Actions\Action (the two are unrelated classes — a table's own
 * ->actions([...]) can't accept the page-header Action type).
 *
 * No new admin/owner check was needed for either new location: both
 * ListProposals's table and ProspectProposalsTable's query are built on
 * ProposalResource::getEloquentQuery(), which already applies the same
 * visibleTo() scoping the Edit/View pages rely on — an employee's own
 * table rows are already only their own Proposals, so filled($record->
 * pdf_path) alone is sufficient in both places too.
 */
class ProposalPdfDownloadActionLocationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function proposalFor(User $owner, ?string $pdfPath): Proposal
    {
        $prospect = Prospect::factory()->create(['company_name' => 'Acme Textiles', 'assigned_to' => $owner->id, 'created_by' => $owner->id]);
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
            'pdf_path' => $pdfPath,
        ]);

        if ($pdfPath) {
            Storage::disk('local')->put($pdfPath, 'fake pdf contents');
        }

        return $proposal;
    }

    // --- Proposals list page ---

    public function test_the_list_page_shows_download_pdf_only_on_rows_with_a_pdf_attached(): void
    {
        $employee = User::factory()->create();
        $withPdf = $this->proposalFor($employee, 'proposal-pdfs/with.pdf');
        $withoutPdf = $this->proposalFor($employee, null);

        $this->actingAs($employee);

        Livewire::test(ListProposals::class)
            ->assertTableActionVisible('downloadPdf', $withPdf)
            ->assertTableActionHidden('downloadPdf', $withoutPdf);
    }

    public function test_the_list_page_shows_download_pdf_to_the_assigned_owner(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposalFor($employee, 'proposal-pdfs/with.pdf');

        $this->actingAs($employee);

        Livewire::test(ListProposals::class)
            ->assertTableActionVisible('downloadPdf', $proposal);
    }

    public function test_the_list_page_shows_download_pdf_to_admin(): void
    {
        $employee = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $proposal = $this->proposalFor($employee, 'proposal-pdfs/with.pdf');

        $this->actingAs($admin);

        Livewire::test(ListProposals::class)
            ->assertTableActionVisible('downloadPdf', $proposal);
    }

    /**
     * A different employee never even sees this row at all — ProposalResource
     * ::getEloquentQuery()'s visibleTo() scoping means it simply isn't in
     * their table's query results (the same scoping that already protects
     * the Edit/View pages), so there's no separate "action hidden on a
     * visible row" case to test here — the row itself is absent.
     */
    public function test_a_different_employee_does_not_even_see_the_row(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $proposal = $this->proposalFor($owner, 'proposal-pdfs/with.pdf');

        $this->actingAs($intruder);

        Livewire::test(ListProposals::class)
            ->assertCanNotSeeTableRecords([$proposal]);
    }

    public function test_clicking_download_pdf_on_the_list_page_downloads_the_correct_file(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposalFor($employee, 'proposal-pdfs/with.pdf');

        $this->actingAs($employee);

        Livewire::test(ListProposals::class)
            ->callTableAction('downloadPdf', $proposal)
            ->assertFileDownloaded("Acme Textiles - {$proposal->id}.pdf");
    }

    // --- Prospect View mini-table ---

    public function test_the_mini_table_shows_download_pdf_only_on_rows_with_a_pdf_attached(): void
    {
        $employee = User::factory()->create();
        $withPdf = $this->proposalFor($employee, 'proposal-pdfs/with.pdf');

        $prospect = $withPdf->prospect;
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => LeadStage::Validated,
            'temperature' => 'hot',
            'notes' => 'x',
        ]);
        $withoutPdf = Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => ProposalStage::BeingPrepared,
        ]);

        $this->actingAs($employee);

        Livewire::test(ProspectProposalsTable::class, ['record' => $prospect, 'filters' => []])
            ->assertTableActionVisible('downloadPdf', $withPdf)
            ->assertTableActionHidden('downloadPdf', $withoutPdf);
    }

    public function test_the_mini_table_shows_download_pdf_to_the_assigned_owner(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposalFor($employee, 'proposal-pdfs/with.pdf');

        $this->actingAs($employee);

        Livewire::test(ProspectProposalsTable::class, ['record' => $proposal->prospect, 'filters' => []])
            ->assertTableActionVisible('downloadPdf', $proposal);
    }

    public function test_the_mini_table_shows_download_pdf_to_admin(): void
    {
        $employee = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $proposal = $this->proposalFor($employee, 'proposal-pdfs/with.pdf');

        $this->actingAs($admin);

        Livewire::test(ProspectProposalsTable::class, ['record' => $proposal->prospect, 'filters' => []])
            ->assertTableActionVisible('downloadPdf', $proposal);
    }

    public function test_a_different_employee_does_not_even_see_the_row_in_the_mini_table(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $proposal = $this->proposalFor($owner, 'proposal-pdfs/with.pdf');

        $this->actingAs($intruder);

        Livewire::test(ProspectProposalsTable::class, ['record' => $proposal->prospect, 'filters' => []])
            ->assertCanNotSeeTableRecords([$proposal]);
    }

    public function test_clicking_download_pdf_in_the_mini_table_downloads_the_correct_file(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposalFor($employee, 'proposal-pdfs/with.pdf');

        $this->actingAs($employee);

        Livewire::test(ProspectProposalsTable::class, ['record' => $proposal->prospect, 'filters' => []])
            ->callTableAction('downloadPdf', $proposal)
            ->assertFileDownloaded("Acme Textiles - {$proposal->id}.pdf");
    }
}
