<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Enums\DemoMode;
use App\Enums\DemoStatus;
use App\Enums\FollowUpStatus;
use App\Enums\LeadStage;
use App\Enums\ProposalStage;
use App\Filament\Resources\AppointmentResource;
use App\Filament\Resources\ProposalResource;
use App\Filament\Resources\ProspectResource;
use App\Filament\Resources\ProspectResource\Pages\ListProspects;
use App\Filament\Resources\ProspectResource\Pages\ViewProspect;
use App\Filament\Widgets\ProspectAppointmentsTable;
use App\Filament\Widgets\ProspectCallRecordsTable;
use App\Filament\Widgets\ProspectDemosTable;
use App\Filament\Widgets\ProspectFollowUpsTable;
use App\Filament\Widgets\ProspectLeadsTable;
use App\Filament\Widgets\ProspectProposalsTable;
use App\Models\Appointment;
use App\Models\CallRecord;
use App\Models\Demo;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Clicking a company from the global search bar now lands on a read-only
 * View page (7 tabs: Overview + one per activity type) instead of jumping
 * straight to Edit — see ProspectResource::getGlobalSearchResultUrl() and
 * ViewProspect. This is deliberately a global-search-only change: every
 * other way of reaching a Prospect (the Database list's own row actions)
 * is untouched.
 *
 * Tab-navigation mechanics themselves (default tab, ?section= resolution,
 * fallback for an unknown section key) are covered separately in
 * ProspectViewSectionNavigationTest.php, mirroring how
 * ViewCommercialVersionSectionNavigationTest.php is split out from the
 * rest of that page's tests. This file covers the mini-tables' own
 * content/scoping/filter correctness — each is instantiated directly as
 * an isolated Livewire component (Livewire::test($widgetClass, [...]))
 * wherever that's sufficient, since Phase: tab-restructure changed only
 * how each table is *mounted* into the page (Infolists\Components\
 * Livewire inside a Tab, not getFooterWidgets()) — not the table classes
 * themselves, their query logic, or InteractsWithPageFilters.
 */
class ProspectViewPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_search_result_url_points_to_the_view_page_not_edit(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create();

        $this->actingAs($admin);

        $this->assertSame(
            ProspectResource::getUrl('view', ['record' => $prospect]),
            ProspectResource::getGlobalSearchResultUrl($prospect),
        );
        $this->assertNotSame(
            ProspectResource::getUrl('edit', ['record' => $prospect]),
            ProspectResource::getGlobalSearchResultUrl($prospect),
        );
    }

    public function test_database_list_row_actions_still_include_a_working_edit_action(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create();

        $this->actingAs($admin);

        Livewire::test(ListProspects::class)
            ->assertTableActionVisible('edit', $prospect)
            ->assertTableActionHasUrl('edit', ProspectResource::getUrl('edit', ['record' => $prospect]), $prospect);
    }

    public function test_view_page_shows_the_prospects_own_details_with_an_edit_action(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create([
            'company_name' => 'Acme Textiles',
            'contact_person' => 'Jane Doe',
        ]);

        $this->actingAs($admin);

        // The details section is now a read-only infolist (collapsed by
        // default), not a form, so there's no form state to assert against
        // — the company name is instead the page's own native heading
        // (always visible regardless of the section's collapse state), and
        // the infolist's own field values are checked via the rendered
        // output instead.
        $test = Livewire::test(ViewProspect::class, ['record' => $prospect->getRouteKey()])
            ->assertActionVisible('edit')
            ->assertSee('Jane Doe');

        $this->assertSame('Acme Textiles', $test->instance()->getHeading());
    }

    /**
     * Root cause of the Period/Employee filters silently doing nothing in
     * the browser: $filters started, and stayed, raw PHP null — nothing
     * ever filled the filters form with its own resolved defaults (unlike
     * Filament's own Dashboard pages, see vendor/filament/filament/src/
     * Pages/Dashboard/Concerns/HasFilters::mountHasFilters(), which calls
     * $this->getFiltersForm()->fill($this->filters) during mount for
     * exactly this reason). Confirmed via a real headless-Chromium session
     * (Livewire::test() can't reach this — it only exercises a single
     * PHP-side interaction, not the browser's own JS runtime): with
     * $filters left null, Livewire's client-side JS threw "Cannot set
     * properties of null" the instant the Period select's
     * wire:model.live tried to write filters.period, aborting the
     * request before it was ever sent — so the Period select did nothing,
     * and the Employee select (a searchable Select, entangled via Alpine
     * rather than a plain wire:model) failed outright with "Livewire
     * property ['filters.employee_id'] cannot be found". Once mount()
     * fills the form, $filters becomes a proper array immediately and
     * both selects work end-to-end in the browser.
     */
    public function test_the_filters_property_is_populated_with_resolved_defaults_after_mount(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id]);

        $this->actingAs($admin);

        $filters = Livewire::test(ViewProspect::class, ['record' => $prospect->getRouteKey()])
            ->instance()
            ->filters;

        $this->assertIsArray($filters, '$filters must not be left null after mount, or the filters form is unusable client-side.');
        $this->assertSame('all_time', $filters['period'] ?? null);
        $this->assertArrayHasKey('employee_id', $filters);
    }

    /**
     * getFooterWidgets() is gone from this page entirely (Phase:
     * tab-restructure Step 2) — every mini-table now mounts exactly once,
     * as an Infolists\Components\Livewire entry inside its own Tab. This
     * guards against that regressing: Filament's Infolist Tabs renders
     * every tab's content into the DOM up front (all six mini-tables are
     * genuinely mounted and reactive even before their tab is ever
     * clicked — confirmed directly via Playwright: switching Period while
     * on an inactive tab still re-filters it), toggling only *visibility*
     * client-side, so a real double-mount here would surface exactly the
     * way the old getFooterWidgets()-plus-manual-render bug did: a
     * duplicate heading and duplicate Livewire key for one table. This
     * asserts against the actual rendered HTML precisely because that's
     * the one thing a single-widget-in-isolation test (see the other
     * tests in this file) structurally cannot catch — plus the Company
     * Details section itself (the Overview tab's own content), which a
     * footer-widget architecture never had to guard at all.
     */
    public function test_the_view_page_does_not_render_each_mini_table_widget_twice(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create(['company_name' => 'Acme Textiles']);

        $this->actingAs($admin);

        $html = Livewire::test(ViewProspect::class, ['record' => $prospect->getRouteKey()])->html();

        $this->assertSame(1, substr_count($html, 'Company Details'), 'The Overview tab\'s Company Details section should appear exactly once.');

        foreach ([
            'Call Records — Acme Textiles',
            'Follow-Ups — Acme Textiles',
            'Appointments — Acme Textiles',
            'Leads — Acme Textiles',
            'Demos — Acme Textiles',
            'Proposals — Acme Textiles',
        ] as $heading) {
            $this->assertSame(
                1,
                substr_count($html, $heading),
                "\"{$heading}\" should appear exactly once in the rendered page, not duplicated.",
            );
        }
    }

    public function test_each_mini_table_only_shows_this_companys_records(): void
    {
        $admin = User::factory()->admin()->create();
        $thisCompany = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id]);
        $otherCompany = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id]);

        $thisCall = CallRecord::create(['prospect_id' => $thisCompany->id, 'user_id' => $admin->id, 'called_at' => now(), 'outcome' => CallOutcome::NoAnswer]);
        CallRecord::create(['prospect_id' => $otherCompany->id, 'user_id' => $admin->id, 'called_at' => now(), 'outcome' => CallOutcome::NoAnswer]);

        $thisFollowUp = FollowUp::create(['prospect_id' => $thisCompany->id, 'user_id' => $admin->id, 'follow_up_at' => now()->addDay(), 'reason' => 'Callback', 'status' => FollowUpStatus::Pending]);
        FollowUp::create(['prospect_id' => $otherCompany->id, 'user_id' => $admin->id, 'follow_up_at' => now()->addDay(), 'reason' => 'Callback', 'status' => FollowUpStatus::Pending]);

        $thisAppointment = Appointment::create(['prospect_id' => $thisCompany->id, 'assigned_to' => $admin->id, 'created_by' => $admin->id, 'appointment_at' => now()->addDay(), 'stage' => 'appointment_made']);
        Appointment::create(['prospect_id' => $otherCompany->id, 'assigned_to' => $admin->id, 'created_by' => $admin->id, 'appointment_at' => now()->addDay(), 'stage' => 'appointment_made']);

        $thisLead = Lead::create(['prospect_id' => $thisCompany->id, 'assigned_to' => $admin->id, 'created_by' => $admin->id, 'stage' => LeadStage::RequirementCollection, 'temperature' => 'warm']);
        Lead::create(['prospect_id' => $otherCompany->id, 'assigned_to' => $admin->id, 'created_by' => $admin->id, 'stage' => LeadStage::RequirementCollection, 'temperature' => 'warm']);

        $thisProposal = Proposal::create(['lead_id' => $thisLead->id, 'prospect_id' => $thisCompany->id, 'assigned_to' => $admin->id, 'created_by' => $admin->id, 'stage' => ProposalStage::BeingPrepared]);
        $otherLead = Lead::create(['prospect_id' => $otherCompany->id, 'assigned_to' => $admin->id, 'created_by' => $admin->id, 'stage' => LeadStage::RequirementCollection, 'temperature' => 'warm']);
        Proposal::create(['lead_id' => $otherLead->id, 'prospect_id' => $otherCompany->id, 'assigned_to' => $admin->id, 'created_by' => $admin->id, 'stage' => ProposalStage::BeingPrepared]);

        $thisDemo = Demo::create(['lead_id' => $thisLead->id, 'prospect_id' => $thisCompany->id, 'assigned_to' => $admin->id, 'created_by' => $admin->id, 'demo_at' => now()->addDay(), 'mode' => DemoMode::Online, 'meeting_link' => 'https://meet.example.com/demo', 'status' => DemoStatus::Scheduled]);
        Demo::create(['lead_id' => $otherLead->id, 'prospect_id' => $otherCompany->id, 'assigned_to' => $admin->id, 'created_by' => $admin->id, 'demo_at' => now()->addDay(), 'mode' => DemoMode::Online, 'meeting_link' => 'https://meet.example.com/demo', 'status' => DemoStatus::Scheduled]);

        $this->actingAs($admin);

        Livewire::test(ProspectCallRecordsTable::class, ['record' => $thisCompany, 'filters' => []])
            ->assertCanSeeTableRecords([$thisCall])
            ->assertCanNotSeeTableRecords([CallRecord::where('prospect_id', $otherCompany->id)->first()]);

        Livewire::test(ProspectFollowUpsTable::class, ['record' => $thisCompany, 'filters' => []])
            ->assertCanSeeTableRecords([$thisFollowUp])
            ->assertCanNotSeeTableRecords([FollowUp::where('prospect_id', $otherCompany->id)->first()]);

        Livewire::test(ProspectAppointmentsTable::class, ['record' => $thisCompany, 'filters' => []])
            ->assertCanSeeTableRecords([$thisAppointment])
            ->assertCanNotSeeTableRecords([Appointment::where('prospect_id', $otherCompany->id)->first()]);

        Livewire::test(ProspectLeadsTable::class, ['record' => $thisCompany, 'filters' => []])
            ->assertCanSeeTableRecords([$thisLead])
            ->assertCanNotSeeTableRecords([Lead::where('prospect_id', $otherCompany->id)->first()]);

        Livewire::test(ProspectProposalsTable::class, ['record' => $thisCompany, 'filters' => []])
            ->assertCanSeeTableRecords([$thisProposal])
            ->assertCanNotSeeTableRecords([Proposal::where('prospect_id', $otherCompany->id)->first()]);

        Livewire::test(ProspectDemosTable::class, ['record' => $thisCompany, 'filters' => []])
            ->assertCanSeeTableRecords([$thisDemo])
            ->assertCanNotSeeTableRecords([Demo::where('prospect_id', $otherCompany->id)->first()]);
    }

    public function test_mini_tables_do_not_show_the_redundant_company_column(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create(['company_name' => 'Acme Textiles', 'assigned_to' => $admin->id, 'created_by' => $admin->id]);
        CallRecord::create(['prospect_id' => $prospect->id, 'user_id' => $admin->id, 'called_at' => now(), 'outcome' => CallOutcome::NoAnswer]);

        $this->actingAs($admin);

        // Inspecting the widget's registered columns directly is a more
        // precise assertion than scraping rendered HTML for the word
        // "Company", which the page's own details section already
        // contains legitimately.
        $columns = Livewire::test(ProspectCallRecordsTable::class, ['record' => $prospect, 'filters' => []])
            ->instance()
            ->getTable()
            ->getColumns();

        $this->assertArrayNotHasKey('prospect.company_name', $columns);
        $this->assertArrayHasKey('called_at', $columns);
    }

    public function test_period_filter_scopes_each_mini_table(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id]);

        $today = CallRecord::create(['prospect_id' => $prospect->id, 'user_id' => $admin->id, 'called_at' => now(), 'outcome' => CallOutcome::NoAnswer]);
        $lastWeek = CallRecord::create(['prospect_id' => $prospect->id, 'user_id' => $admin->id, 'called_at' => now()->subWeek(), 'outcome' => CallOutcome::NoAnswer]);

        $this->actingAs($admin);

        Livewire::test(ProspectCallRecordsTable::class, ['record' => $prospect, 'filters' => ['period' => 'today']])
            ->assertCanSeeTableRecords([$today])
            ->assertCanNotSeeTableRecords([$lastWeek]);

        Livewire::test(ProspectCallRecordsTable::class, ['record' => $prospect, 'filters' => ['period' => 'all_time']])
            ->assertCanSeeTableRecords([$today, $lastWeek]);
    }

    /**
     * Closes the test-coverage gap the tab-restructure investigation
     * found: Period was previously only directly query-tested for Call
     * Records above. This covers the other five.
     *
     * Backdating via a raw DB update (not passing 'created_at' to
     * create()) matters: Eloquent's HasTimestamps::updateTimestamps()
     * silently overwrites any 'created_at' passed in create()'s own
     * attributes array with now() — confirmed directly while first
     * reproducing this during the original investigation, where every
     * "old" fixture record built that way ended up with today's
     * timestamp regardless, making every one of these five look "broken"
     * as a false positive. A raw DB::table(...)->update() after creation
     * bypasses that entirely.
     */
    public function test_period_filter_scopes_the_remaining_five_mini_tables(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id]);

        $todayFollowUp = FollowUp::create(['prospect_id' => $prospect->id, 'user_id' => $admin->id, 'follow_up_at' => now()->addDay(), 'reason' => 'Callback', 'status' => FollowUpStatus::Pending]);
        $oldFollowUp = FollowUp::create(['prospect_id' => $prospect->id, 'user_id' => $admin->id, 'follow_up_at' => now()->addDay(), 'reason' => 'Callback', 'status' => FollowUpStatus::Pending]);
        \DB::table('follow_ups')->where('id', $oldFollowUp->id)->update(['created_at' => now()->subWeek()]);

        $todayAppointment = Appointment::create(['prospect_id' => $prospect->id, 'assigned_to' => $admin->id, 'created_by' => $admin->id, 'appointment_at' => now()->addDay(), 'stage' => 'appointment_made']);
        $oldAppointment = Appointment::create(['prospect_id' => $prospect->id, 'assigned_to' => $admin->id, 'created_by' => $admin->id, 'appointment_at' => now()->addDay(), 'stage' => 'appointment_made']);
        \DB::table('appointments')->where('id', $oldAppointment->id)->update(['created_at' => now()->subWeek()]);

        $todayLead = Lead::create(['prospect_id' => $prospect->id, 'assigned_to' => $admin->id, 'created_by' => $admin->id, 'stage' => LeadStage::RequirementCollection, 'temperature' => 'warm']);
        $oldLead = Lead::create(['prospect_id' => $prospect->id, 'assigned_to' => $admin->id, 'created_by' => $admin->id, 'stage' => LeadStage::RequirementCollection, 'temperature' => 'warm']);
        \DB::table('leads')->where('id', $oldLead->id)->update(['created_at' => now()->subWeek()]);

        $todayDemo = Demo::create(['prospect_id' => $prospect->id, 'lead_id' => $todayLead->id, 'assigned_to' => $admin->id, 'created_by' => $admin->id, 'demo_at' => now()->addDay(), 'mode' => DemoMode::Online, 'meeting_link' => 'https://meet.example.com/1', 'status' => DemoStatus::Scheduled]);
        $oldDemo = Demo::create(['prospect_id' => $prospect->id, 'lead_id' => $oldLead->id, 'assigned_to' => $admin->id, 'created_by' => $admin->id, 'demo_at' => now()->addDay(), 'mode' => DemoMode::Online, 'meeting_link' => 'https://meet.example.com/2', 'status' => DemoStatus::Scheduled]);
        \DB::table('demos')->where('id', $oldDemo->id)->update(['created_at' => now()->subWeek()]);

        $todayProposal = Proposal::create(['prospect_id' => $prospect->id, 'lead_id' => $todayLead->id, 'assigned_to' => $admin->id, 'created_by' => $admin->id, 'stage' => ProposalStage::BeingPrepared]);
        $oldProposal = Proposal::create(['prospect_id' => $prospect->id, 'lead_id' => $oldLead->id, 'assigned_to' => $admin->id, 'created_by' => $admin->id, 'stage' => ProposalStage::BeingPrepared]);
        \DB::table('proposals')->where('id', $oldProposal->id)->update(['created_at' => now()->subWeek()]);

        $this->actingAs($admin);

        Livewire::test(ProspectFollowUpsTable::class, ['record' => $prospect, 'filters' => ['period' => 'today']])
            ->assertCanSeeTableRecords([$todayFollowUp])->assertCanNotSeeTableRecords([$oldFollowUp]);

        Livewire::test(ProspectAppointmentsTable::class, ['record' => $prospect, 'filters' => ['period' => 'today']])
            ->assertCanSeeTableRecords([$todayAppointment])->assertCanNotSeeTableRecords([$oldAppointment]);

        Livewire::test(ProspectLeadsTable::class, ['record' => $prospect, 'filters' => ['period' => 'today']])
            ->assertCanSeeTableRecords([$todayLead])->assertCanNotSeeTableRecords([$oldLead]);

        Livewire::test(ProspectDemosTable::class, ['record' => $prospect, 'filters' => ['period' => 'today']])
            ->assertCanSeeTableRecords([$todayDemo])->assertCanNotSeeTableRecords([$oldDemo]);

        Livewire::test(ProspectProposalsTable::class, ['record' => $prospect, 'filters' => ['period' => 'today']])
            ->assertCanSeeTableRecords([$todayProposal])->assertCanNotSeeTableRecords([$oldProposal]);
    }

    public function test_employee_filter_is_visible_for_admins_and_hidden_for_regular_employees(): void
    {
        $admin = User::factory()->admin()->create();
        $adminsProspect = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id]);

        $this->actingAs($admin);

        Livewire::test(ViewProspect::class, ['record' => $adminsProspect->getRouteKey()])
            ->assertFormFieldIsVisible('employee_id', 'filtersForm');

        $employee = User::factory()->create();
        $employeesProspect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);

        $this->actingAs($employee);

        Livewire::test(ViewProspect::class, ['record' => $employeesProspect->getRouteKey()])
            ->assertFormFieldIsHidden('employee_id', 'filtersForm');
    }

    public function test_employee_filter_scopes_each_mini_table_to_the_selected_employee(): void
    {
        $nithin = User::factory()->create();
        $kural = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id]);

        $nithinsAppointment = Appointment::create(['prospect_id' => $prospect->id, 'assigned_to' => $nithin->id, 'created_by' => $admin->id, 'appointment_at' => now()->addDay(), 'stage' => 'appointment_made']);
        $kuralsAppointment = Appointment::create(['prospect_id' => $prospect->id, 'assigned_to' => $kural->id, 'created_by' => $admin->id, 'appointment_at' => now()->addDay(), 'stage' => 'appointment_made']);

        $this->actingAs($admin);

        Livewire::test(ProspectAppointmentsTable::class, ['record' => $prospect, 'filters' => ['employee_id' => $nithin->id]])
            ->assertCanSeeTableRecords([$nithinsAppointment])
            ->assertCanNotSeeTableRecords([$kuralsAppointment]);
    }

    /**
     * Closes the test-coverage gap the tab-restructure investigation
     * found: Employee was previously only directly query-tested for
     * Appointments above. This covers the other five — note the
     * ownership column genuinely differs by resource (user_id for Call
     * Records and Follow-Ups, assigned_to for Leads/Demos/Proposals),
     * confirmed against each widget's own ->query() rather than assumed
     * uniform.
     */
    public function test_employee_filter_scopes_the_remaining_five_mini_tables(): void
    {
        $nithin = User::factory()->create();
        $kural = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id]);

        $nithinsCall = CallRecord::create(['prospect_id' => $prospect->id, 'user_id' => $nithin->id, 'called_at' => now(), 'outcome' => CallOutcome::NoAnswer]);
        $kuralsCall = CallRecord::create(['prospect_id' => $prospect->id, 'user_id' => $kural->id, 'called_at' => now(), 'outcome' => CallOutcome::NoAnswer]);

        $nithinsFollowUp = FollowUp::create(['prospect_id' => $prospect->id, 'user_id' => $nithin->id, 'follow_up_at' => now()->addDay(), 'reason' => 'Callback', 'status' => FollowUpStatus::Pending]);
        $kuralsFollowUp = FollowUp::create(['prospect_id' => $prospect->id, 'user_id' => $kural->id, 'follow_up_at' => now()->addDay(), 'reason' => 'Callback', 'status' => FollowUpStatus::Pending]);

        $nithinsLead = Lead::create(['prospect_id' => $prospect->id, 'assigned_to' => $nithin->id, 'created_by' => $admin->id, 'stage' => LeadStage::RequirementCollection, 'temperature' => 'warm']);
        $kuralsLead = Lead::create(['prospect_id' => $prospect->id, 'assigned_to' => $kural->id, 'created_by' => $admin->id, 'stage' => LeadStage::RequirementCollection, 'temperature' => 'warm']);

        $nithinsDemo = Demo::create(['prospect_id' => $prospect->id, 'lead_id' => $nithinsLead->id, 'assigned_to' => $nithin->id, 'created_by' => $admin->id, 'demo_at' => now()->addDay(), 'mode' => DemoMode::Online, 'meeting_link' => 'https://meet.example.com/1', 'status' => DemoStatus::Scheduled]);
        $kuralsDemo = Demo::create(['prospect_id' => $prospect->id, 'lead_id' => $kuralsLead->id, 'assigned_to' => $kural->id, 'created_by' => $admin->id, 'demo_at' => now()->addDay(), 'mode' => DemoMode::Online, 'meeting_link' => 'https://meet.example.com/2', 'status' => DemoStatus::Scheduled]);

        $nithinsProposal = Proposal::create(['prospect_id' => $prospect->id, 'lead_id' => $nithinsLead->id, 'assigned_to' => $nithin->id, 'created_by' => $admin->id, 'stage' => ProposalStage::BeingPrepared]);
        $kuralsProposal = Proposal::create(['prospect_id' => $prospect->id, 'lead_id' => $kuralsLead->id, 'assigned_to' => $kural->id, 'created_by' => $admin->id, 'stage' => ProposalStage::BeingPrepared]);

        $this->actingAs($admin);

        Livewire::test(ProspectCallRecordsTable::class, ['record' => $prospect, 'filters' => ['employee_id' => $nithin->id]])
            ->assertCanSeeTableRecords([$nithinsCall])->assertCanNotSeeTableRecords([$kuralsCall]);

        Livewire::test(ProspectFollowUpsTable::class, ['record' => $prospect, 'filters' => ['employee_id' => $nithin->id]])
            ->assertCanSeeTableRecords([$nithinsFollowUp])->assertCanNotSeeTableRecords([$kuralsFollowUp]);

        Livewire::test(ProspectLeadsTable::class, ['record' => $prospect, 'filters' => ['employee_id' => $nithin->id]])
            ->assertCanSeeTableRecords([$nithinsLead])->assertCanNotSeeTableRecords([$kuralsLead]);

        Livewire::test(ProspectDemosTable::class, ['record' => $prospect, 'filters' => ['employee_id' => $nithin->id]])
            ->assertCanSeeTableRecords([$nithinsDemo])->assertCanNotSeeTableRecords([$kuralsDemo]);

        Livewire::test(ProspectProposalsTable::class, ['record' => $prospect, 'filters' => ['employee_id' => $nithin->id]])
            ->assertCanSeeTableRecords([$nithinsProposal])->assertCanNotSeeTableRecords([$kuralsProposal]);
    }

    public function test_a_regular_employee_viewing_this_page_only_sees_their_own_records_in_each_mini_table(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);

        $ownersCall = CallRecord::create(['prospect_id' => $prospect->id, 'user_id' => $owner->id, 'called_at' => now(), 'outcome' => CallOutcome::NoAnswer]);
        $intrudersCall = CallRecord::create(['prospect_id' => $prospect->id, 'user_id' => $intruder->id, 'called_at' => now(), 'outcome' => CallOutcome::NoAnswer]);

        // The intruder can't even reach the page for a Prospect they don't
        // own — ProspectResource::getEloquentQuery()'s visibleTo() scoping
        // means the record simply isn't found for them (a 404, the same
        // query-scoping behavior used everywhere else in this app), not a
        // 403.
        $this->actingAs($intruder);
        $this->get(ProspectResource::getUrl('view', ['record' => $prospect]))->assertNotFound();

        // As the owner, the Call Records mini-table only shows calls made
        // by the owner themselves — CallRecord::scopeVisibleTo() scopes by
        // who made the call, not who the company is assigned to, so this
        // also proves the widget goes through CallRecordResource::
        // getEloquentQuery() rather than an unscoped raw query.
        $this->actingAs($owner);
        Livewire::test(ProspectCallRecordsTable::class, ['record' => $prospect, 'filters' => []])
            ->assertCanSeeTableRecords([$ownersCall])
            ->assertCanNotSeeTableRecords([$intrudersCall]);
    }

    /**
     * @return array<class-string>
     */
    private function miniTableWidgetClasses(): array
    {
        return [
            ProspectCallRecordsTable::class,
            ProspectFollowUpsTable::class,
            ProspectAppointmentsTable::class,
            ProspectLeadsTable::class,
            ProspectDemosTable::class,
            ProspectProposalsTable::class,
        ];
    }

    /**
     * Filters live on the parent ViewProspect page, not on each mini-table's
     * own Table object, so nothing tells a table to re-query when $filters
     * changes except this explicit hook — this is the actual fix for the
     * "filters don't affect the mini-tables" bug, so it's asserted directly
     * (a Mockery partial mock in place of the real Livewire/Table lifecycle,
     * since resetTable() itself requires that full lifecycle to run
     * un-mocked) rather than only inferred from the end-to-end behavior,
     * which the PHP test harness can't reliably exercise across components
     * (see class docblock note below).
     */
    public function test_updated_filters_resets_the_table_on_all_six_mini_table_widgets(): void
    {
        foreach ($this->miniTableWidgetClasses() as $widgetClass) {
            $widget = Mockery::mock($widgetClass)->makePartial();
            $widget->shouldReceive('resetTable')->once();

            $widget->updatedFilters();
        }
    }

    /**
     * These mini-tables are the page's main content, not a below-the-fold
     * nicety, and $isLazy = true (Filament's default) would mean their real
     * content only appears after a follow-up request — an extra hop the
     * already cross-component filter reactivity doesn't need.
     */
    public function test_all_six_mini_table_widgets_are_not_lazy(): void
    {
        foreach ($this->miniTableWidgetClasses() as $widgetClass) {
            $reflection = new \ReflectionClass($widgetClass);
            $property = $reflection->getProperty('isLazy');
            $property->setAccessible(true);

            $this->assertFalse($property->getValue());
        }
    }

    /**
     * Every row in each mini-table already belongs to this one company, so
     * a search bar has nothing meaningful to search by — the Follow-Ups
     * table's `reason` column was the only one left searchable once Company
     * is excluded, which is why it alone had shown a search bar.
     */
    public function test_all_six_mini_table_widgets_disable_search(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id]);

        $this->actingAs($admin);

        foreach ($this->miniTableWidgetClasses() as $widgetClass) {
            $isSearchable = Livewire::test($widgetClass, ['record' => $prospect, 'filters' => []])
                ->instance()
                ->getTable()
                ->isSearchable();

            $this->assertFalse($isSearchable, "{$widgetClass} should not be searchable.");
        }
    }
}
