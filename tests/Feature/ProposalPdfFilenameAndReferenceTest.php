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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Two related follow-ups on the Proposal PDF feature:
 *
 * 1. The downloaded file is named "{Company Name} - {Proposal ID}.pdf"
 *    (e.g. "Chennai Precision Engineering Works - 33.pdf") instead of the
 *    generic "proposal-33.pdf" — the company name is free text, so
 *    ProposalResource::pdfDownloadFilename() sanitizes characters that
 *    are invalid in a Windows filename (also the ones most likely to
 *    confuse a browser's own "save as" naming) before building it.
 * 2. The Proposal's own database ID (the same one used in that filename,
 *    not a separate reference number) is shown as the page subheading on
 *    Edit and View, so it's visible before ever downloading the PDF.
 */
class ProposalPdfFilenameAndReferenceTest extends TestCase
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
            'pdf_path' => 'proposal-pdfs/existing.pdf',
        ]);
        Storage::disk('local')->put('proposal-pdfs/existing.pdf', 'fake pdf contents');

        return $proposal;
    }

    public function test_the_downloaded_filename_is_company_name_dash_proposal_id(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposalFor($employee, 'Chennai Precision Engineering Works');

        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->callAction('downloadPdf')
            ->assertFileDownloaded("Chennai Precision Engineering Works - {$proposal->id}.pdf");
    }

    public function test_the_downloaded_filename_matches_from_the_view_page_too(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposalFor($employee, 'Chennai Precision Engineering Works');

        $this->actingAs($employee);

        Livewire::test(ViewProposal::class, ['record' => $proposal->getRouteKey()])
            ->callAction('downloadPdf')
            ->assertFileDownloaded("Chennai Precision Engineering Works - {$proposal->id}.pdf");
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unsafeCompanyNameProvider(): array
    {
        return [
            'forward slash' => ['A/B Testing Co', 'A B Testing Co'],
            'backslash' => ['Smith\\Jones Ltd', 'Smith Jones Ltd'],
            'colon and quotes' => ['Bob\'s "Best" Co: Ltd', 'Bob\'s Best Co Ltd'],
            'wildcard and question mark' => ['What? Co* Ltd', 'What Co Ltd'],
            'angle brackets and pipe' => ['A<B>C|D Co', 'A B C D Co'],
            'ampersand and comma are left alone' => ['Smith & Sons, Inc.', 'Smith & Sons, Inc.'],
        ];
    }

    #[DataProvider('unsafeCompanyNameProvider')]
    public function test_the_downloaded_filename_sanitizes_unsafe_characters_in_the_company_name(string $companyName, string $sanitized): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposalFor($employee, $companyName);

        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->callAction('downloadPdf')
            ->assertFileDownloaded("{$sanitized} - {$proposal->id}.pdf");
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
