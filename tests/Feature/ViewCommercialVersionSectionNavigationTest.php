<?php

namespace Tests\Feature;

use App\Enums\ProposalVersionLifecycle;
use App\Enums\UserRole;
use App\Filament\Resources\ProposalResource;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use App\Models\Prospect;
use App\Models\User;
use App\Services\ProposalReleaseService;
use App\Services\ProposalVersionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * UI/navigation pass only (no business-logic change): App\Support\Filament\
 * SectionTabs as applied to ViewCommercialVersion, the secondary rollout
 * target after ManageCommercialVersion. The page's 9 original sections
 * (unchanged content/visibility/description — see the page's own infolist()
 * docblock) are grouped into 6 tabs: Overview (Identity/State + Customer
 * Snapshot + Commercial Content + Totals — 4 small, non-repeatable
 * "what is this Version" sections that were never independently long
 * enough to need their own tab), Workflow Evidence, PDF History,
 * Release & Send, Client Responses, Line Items.
 *
 * Real-data rendering and read-only-ness are already covered exhaustively
 * by ProposalVersionHistoryViewTest.php and ManageCommercialVersionPdf
 * ActionsTest.php's historical-page tests (all of which still pass
 * unchanged against the tabbed page — confirmed directly). This file
 * covers only the section-navigation mechanics themselves, using the same
 * `tabs[<N> - 1]` fingerprint technique as ManageCommercialVersion
 * SectionNavigationTest.php — see that file's own docblock for exactly why
 * this is the correct, non-brittle way to verify server-computed initial
 * tab selection without a real browser.
 */
class ViewCommercialVersionSectionNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config(['aculyze.organization_identity' => [
            'legal_name' => 'Aculyze Solutions Pvt Ltd',
            'registered_address' => '123 Business Park, Bengaluru',
            'gstin' => '29ABCDE1234F1Z5',
        ]]);
    }

    /** @return array{employee: User, manager: User, seniorManager: User} */
    private function hierarchy(): array
    {
        $seniorManager = User::factory()->admin()->create();
        $manager = User::factory()->create(['role' => UserRole::Manager, 'manager_id' => $seniorManager->id]);
        $employee = User::factory()->create(['role' => UserRole::Employee, 'manager_id' => $manager->id]);

        return compact('employee', 'manager', 'seniorManager');
    }

    private function approvedProposal(User $employee, User $manager, User $seniorManager): Proposal
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $lead = Lead::create([
            'prospect_id' => $prospect->id, 'assigned_to' => $employee->id, 'created_by' => $employee->id,
            'stage' => 'validated', 'temperature' => 'hot', 'notes' => 'Fixture.',
        ]);
        $proposal = Proposal::create([
            'lead_id' => $lead->id, 'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id, 'created_by' => $employee->id, 'stage' => 'being_prepared',
        ]);
        $version = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'lifecycle_status' => ProposalVersionLifecycle::Draft,
            'customer_name_snapshot' => 'Acme Corp',
        ]);
        ProposalVersionLine::create([
            'proposal_version_id' => $version->id, 'line_number' => 1,
            'item_name' => 'Widget', 'quantity' => 1, 'unit_price' => 100,
        ]);
        $proposal->forceFill(['current_version_id' => $version->id])->save();

        app(ProposalVersionWorkflowService::class)->submit($version->fresh(), $manager);
        app(ProposalVersionWorkflowService::class)->approve($version->fresh(), $seniorManager);

        return $proposal->fresh();
    }

    private function url(Proposal $proposal, ProposalVersion $version, ?string $section = null): string
    {
        $url = ProposalResource::getUrl('commercial-version', ['record' => $proposal, 'version' => $version]);

        return $section === null ? $url : "{$url}?section={$section}";
    }

    private function activeTabIndex(TestResponse $response): ?int
    {
        preg_match('/tabs\[(\d+) - 1\]/', $response->getContent(), $matches);

        return isset($matches[1]) ? (int) $matches[1] : null;
    }

    public function test_default_tab_is_overview(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);
        $version = $proposal->fresh()->currentVersion;

        $response = $this->actingAs($manager)->get($this->url($proposal, $version));

        $this->assertSame(1, $this->activeTabIndex($response));
        $response->assertSee('Acme Corp');
    }

    public function test_selecting_workflow_evidence_resolves_to_its_own_position(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);
        $version = $proposal->fresh()->currentVersion;

        $response = $this->actingAs($manager)
            ->get($this->url($proposal, $version, 'commercial-version-detail-workflow-evidence-tab'));

        $this->assertSame(2, $this->activeTabIndex($response));
        $response->assertSee($manager->name)->assertSee($seniorManager->name);
    }

    public function test_selecting_pdf_history_resolves_to_its_own_position(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);
        $version = $proposal->fresh()->currentVersion;

        $response = $this->actingAs($manager)
            ->get($this->url($proposal, $version, 'commercial-version-detail-pdf-history-tab'));

        $this->assertSame(3, $this->activeTabIndex($response));
        $response->assertSee('Final PDF Artifact History');
    }

    public function test_selecting_release_and_send_resolves_to_its_own_position(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);
        $version = $proposal->fresh()->currentVersion;
        app(ProposalReleaseService::class)->release($version->fresh(), $manager, 'Ready to send.');

        $response = $this->actingAs($manager)
            ->get($this->url($proposal, $version, 'commercial-version-detail-release-send-tab'));

        $this->assertSame(4, $this->activeTabIndex($response));
        $response->assertSee('Ready to send.');
    }

    public function test_selecting_line_items_resolves_to_its_own_position(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);
        $version = $proposal->fresh()->currentVersion;

        // Client Responses tab is hidden (no responses yet), so Line Items
        // is the 5th visible tab, not the 6th.
        $response = $this->actingAs($manager)
            ->get($this->url($proposal, $version, 'commercial-version-detail-line-items-tab'));

        $this->assertSame(5, $this->activeTabIndex($response));
        $response->assertSee('Widget');
    }

    public function test_client_responses_tab_is_hidden_when_there_are_none_and_line_items_still_resolves_correctly(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);
        $version = $proposal->fresh()->currentVersion;

        $response = $this->actingAs($manager)->get($this->url($proposal, $version));

        $response->assertDontSee('commercial-version-detail-client-responses-tab', false);
    }

    public function test_unknown_section_key_falls_back_to_the_default_tab(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);
        $version = $proposal->fresh()->currentVersion;

        $response = $this->actingAs($manager)
            ->get($this->url($proposal, $version, 'garbage-not-real'));

        $this->assertSame(1, $this->activeTabIndex($response));
    }

    public function test_an_employees_hidden_pdf_history_tab_cannot_be_forced_via_the_query_string(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);
        $version = $proposal->fresh()->currentVersion;
        // Not released — canAccessPdfSection() is false for this Employee.

        $response = $this->actingAs($employee)
            ->get($this->url($proposal, $version, 'commercial-version-detail-pdf-history-tab'));

        $this->assertSame(1, $this->activeTabIndex($response));
        $response->assertDontSee('Final PDF Artifact History');
    }

    public function test_a_later_tab_resolves_correctly_even_though_pdf_history_is_hidden_for_this_viewer(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);
        $version = $proposal->fresh()->currentVersion;

        // For this Employee, PDF History is hidden, so Release & Send
        // lands at position 3 (Overview=1, Workflow Evidence=2), not 4.
        $response = $this->actingAs($employee)
            ->get($this->url($proposal, $version, 'commercial-version-detail-release-send-tab'));

        $this->assertSame(3, $this->activeTabIndex($response));
        $response->assertSee('Released At');
    }

    public function test_the_lone_header_action_remains_available_regardless_of_selected_section(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);
        $version = $proposal->fresh()->currentVersion;

        $this->actingAs($manager);

        foreach ([null, 'commercial-version-detail-line-items-tab'] as $section) {
            $this->get($this->url($proposal, $version, $section))
                ->assertSee('Back to Current Commercial Version');
        }
    }
}
