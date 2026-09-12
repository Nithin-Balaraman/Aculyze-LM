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
use App\Services\ProposalPdfArtifactService;
use App\Services\ProposalReleaseService;
use App\Services\ProposalSendService;
use App\Services\ProposalVersionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * UI/navigation pass only (no business-logic change): the reusable
 * App\Support\Filament\SectionTabs pattern as applied to
 * ManageCommercialVersion, the reference/priority implementation.
 *
 * Every one of this page's 7 sections used to render stacked one after
 * another; the underlying data/authorization/workflow behavior is
 * unchanged and already covered exhaustively by
 * ManageCommercialVersion{ClientResponseActions,DraftEditing,
 * HistoryAndRegression,PdfActions,ReleaseSendActions,Visibility,
 * WorkflowActions}Test.php (all 81 of which still pass unchanged against
 * the tabbed page — confirmed directly, not merely assumed). This file
 * covers only the NEW section-navigation mechanics themselves.
 *
 * Filament's native Infolists Tabs renders every tab's content into the
 * DOM on every request and hides inactive panels purely client-side via
 * Alpine (`x-bind:class`) — confirmed directly from the installed
 * package's own Blade source before writing these tests. That means a
 * server-side/Livewire test cannot observe "only the active panel is
 * visible" the way a browser can (Alpine's evaluation never runs in a
 * PHPUnit/Livewire test) — that visual guarantee is instead verified by
 * real browser QA (screenshots captured separately). What CAN be verified
 * here, precisely and non-brittly, is the actual mechanism that decides
 * which tab starts active: the exact integer Filament's Blade view embeds
 * as `tabs[<N> - 1]` inside the page's own inline Alpine `x-init` script —
 * this is the real, load-bearing value the browser uses to pick the
 * initial tab, not a proxy for it.
 */
class ManageCommercialVersionSectionNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Storage::fake('local');

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

    private function draftProposal(User $employee): Proposal
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id, 'email' => 'client@example.com']);
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

        return $proposal->fresh();
    }

    private function approvedProposal(User $employee, User $manager, User $seniorManager): Proposal
    {
        $proposal = $this->draftProposal($employee);

        app(ProposalVersionWorkflowService::class)->submit($proposal->fresh()->currentVersion, $manager);
        app(ProposalVersionWorkflowService::class)->approve($proposal->fresh()->currentVersion, $seniorManager);

        return $proposal->fresh();
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    private function commercialUrl(Proposal $proposal, ?string $section = null): string
    {
        $url = ProposalResource::getUrl('commercial', ['record' => $proposal]);

        return $section === null ? $url : "{$url}?section={$section}";
    }

    /** The 1-based index Filament's Blade view will read as `tabs[<N> - 1]` to pick the initial active tab. */
    private function activeTabIndex(TestResponse $response): ?int
    {
        preg_match('/tabs\[(\d+) - 1\]/', $response->getContent(), $matches);

        return isset($matches[1]) ? (int) $matches[1] : null;
    }

    // --- 1. Default tab ------------------------------------------------

    public function test_default_tab_is_summary(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);

        $response = $this->actingAs($manager)->get($this->commercialUrl($proposal));

        $this->assertSame(1, $this->activeTabIndex($response));
    }

    // --- 2-4, 6-7. Selecting a specific tab resolves to its own position ---

    public function test_selecting_outcome_resolves_to_the_outcome_tabs_own_position(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);

        $response = $this->actingAs($manager)
            ->get($this->commercialUrl($proposal, 'commercial-version-outcome-tab'));

        $this->assertSame(2, $this->activeTabIndex($response));
    }

    public function test_selecting_pdf_resolves_to_the_pdf_tabs_own_position(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);

        $response = $this->actingAs($manager)
            ->get($this->commercialUrl($proposal, 'commercial-version-pdf-tab'));

        $this->assertSame(3, $this->activeTabIndex($response));
    }

    public function test_selecting_release_resolves_to_the_release_tabs_own_position(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);

        $response = $this->actingAs($manager)
            ->get($this->commercialUrl($proposal, 'commercial-version-release-tab'));

        $this->assertSame(4, $this->activeTabIndex($response));
    }

    public function test_selecting_client_response_resolves_to_its_own_position(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);

        $response = $this->actingAs($manager)
            ->get($this->commercialUrl($proposal, 'commercial-version-client-response-tab'));

        $this->assertSame(6, $this->activeTabIndex($response));
    }

    public function test_selecting_version_history_resolves_to_its_own_position(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);

        $response = $this->actingAs($manager)
            ->get($this->commercialUrl($proposal, 'commercial-version-version-history-tab'));

        $this->assertSame(7, $this->activeTabIndex($response));
    }

    // --- 5. Send History tab selection + real data on the same page --------

    public function test_selecting_send_history_resolves_correctly_and_the_page_carries_real_send_data(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);
        $version = $proposal->fresh()->currentVersion;
        app(ProposalReleaseService::class)->release($version->fresh(), $manager);
        app(ProposalSendService::class)->recordManualSend($version->fresh(['proposal']), $employee, ['client@example.com'], [], 'Subject line', null, now(), [], $this->key());

        $response = $this->actingAs($manager)
            ->get($this->commercialUrl($proposal, 'commercial-version-send-history-tab'));

        $this->assertSame(5, $this->activeTabIndex($response));
        $response->assertSee('client@example.com');
        $response->assertSee('Subject line');
    }

    // --- 8. Structural: exactly one tabpanel per visible tab, mutually exclusive markup ---

    public function test_the_page_renders_exactly_one_tabpanel_per_visible_tab(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);

        $response = $this->actingAs($manager)->get($this->commercialUrl($proposal));

        // Senior Manager / Manager can access all 7 (PDF is auto-generated
        // on approval, so it's visible here too).
        $panelCount = substr_count($response->getContent(), 'role="tabpanel"');
        $this->assertSame(7, $panelCount);

        // Every panel is addressable by its own distinct id — never two
        // panels sharing the same anchor.
        preg_match_all('/id="(commercial-version-[a-z-]+-tab)" role="tabpanel"/', $response->getContent(), $matches);
        $this->assertSame(7, count(array_unique($matches[1])));
    }

    // --- 9. Header actions remain available regardless of which tab is selected ---

    public function test_header_actions_remain_available_regardless_of_selected_section(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);

        $this->actingAs($manager);

        foreach ([null, 'commercial-version-outcome-tab', 'commercial-version-version-history-tab'] as $section) {
            $response = $this->get($this->commercialUrl($proposal, $section));
            $response->assertSee('Download Final PDF');
            $response->assertSee('Correct Final PDF');
            $response->assertSee('Release for Client Sending');
        }
    }

    // --- 10. Unknown section key falls back safely --------------------------

    public function test_unknown_section_key_falls_back_to_the_default_tab(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);

        $response = $this->actingAs($manager)
            ->get($this->commercialUrl($proposal, 'not-a-real-section-at-all'));

        $this->assertSame(1, $this->activeTabIndex($response));
    }

    // --- 11. Unauthorized/hidden tab cannot be forced via query string ------

    public function test_an_employees_hidden_pdf_tab_cannot_be_forced_via_the_query_string(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);
        // Not yet released — canAccessPdfSection() is false for this Employee.

        $response = $this->actingAs($employee)
            ->get($this->commercialUrl($proposal, 'commercial-version-pdf-tab'));

        // Falls back to the default (Summary is index 1 for this viewer's
        // own visible-tab list) — never crashes, never opens PDF.
        $this->assertSame(1, $this->activeTabIndex($response));
        $response->assertDontSee('Final PDF');
    }

    public function test_a_tab_hidden_for_the_current_viewer_never_appears_in_the_nav_bar_or_client_side_tab_list(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);

        $response = $this->actingAs($employee)->get($this->commercialUrl($proposal));

        $response->assertDontSee('commercial-version-pdf-tab', false);
        $response->assertDontSee('Final PDF');
    }

    // --- 11b. A LATER tab is still resolved correctly when an EARLIER tab is hidden for the viewer ---

    public function test_a_later_tab_resolves_correctly_even_though_an_earlier_tab_is_hidden_for_this_viewer(): void
    {
        // Regression guard for the exact upstream Filament mismatch this
        // page's SectionTabs usage works around (see SectionTabs's own
        // docblock): an Employee viewing a not-yet-Released Version has
        // "PDF" (position 3) hidden. Deep-linking to "Release" (position 4
        // in the full 7-tab order) must land on Release — not silently
        // slide onto "Send History" the way Filament's own un-pre-filtered
        // index computation would.
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);

        $response = $this->actingAs($employee)
            ->get($this->commercialUrl($proposal, 'commercial-version-release-tab'));

        // For this Employee, PDF is hidden, so the viewer's own visible
        // order is: Summary(1), Outcome(2), Release(3), ... — Release
        // lands at position 3, not 4.
        $this->assertSame(3, $this->activeTabIndex($response));
        $response->assertSee('Release Status');
    }

    // --- 13. Tab bar renders all expected labels for a fully-authorized viewer ---

    public function test_tab_bar_renders_all_seven_labels_for_a_senior_manager(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);

        $response = $this->actingAs($seniorManager)->get($this->commercialUrl($proposal));

        foreach (['Summary', 'Outcome', 'PDF', 'Release', 'Send History', 'Client Response', 'Version History'] as $label) {
            $response->assertSee($label);
        }
    }

    public function test_tab_bar_renders_a_reduced_set_for_an_employee_before_release(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);

        $response = $this->actingAs($employee)->get($this->commercialUrl($proposal));

        preg_match('/value="(\[&quot;[^"]*)"/', $response->getContent(), $matches);
        $this->assertNotNull($matches[1] ?? null);

        // Only PDF is hidden for an Employee before Release
        // (canAccessPdfSection() requires a Manager/Senior Manager, or an
        // Employee with a valid Release — see ProposalPdfArtifactPolicy)
        // — Send History itself stays visible even with zero rows
        // (ProposalSendPolicy::viewHistory() only checks hierarchy
        // visibility, not release/send state), matching the page's
        // pre-existing (unchanged) section-level ->visible() conditions.
        $this->assertStringNotContainsString('pdf-tab', $matches[1]);
        $this->assertStringContainsString('summary-tab', $matches[1]);
        $this->assertStringContainsString('outcome-tab', $matches[1]);
        $this->assertStringContainsString('release-tab', $matches[1]);
        $this->assertStringContainsString('send-history-tab', $matches[1]);
        $this->assertStringContainsString('client-response-tab', $matches[1]);
        $this->assertStringContainsString('version-history-tab', $matches[1]);
    }

    // --- Sticky-nav hook class is present -----------------------------------

    public function test_the_sticky_nav_hook_class_is_present(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);

        $response = $this->actingAs($manager)->get($this->commercialUrl($proposal));

        $response->assertSee('fi-section-tabs', false);
    }
}
