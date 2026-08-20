<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
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
use App\Filament\Widgets\ProspectFollowUpsTable;
use App\Filament\Widgets\ProspectLeadsTable;
use App\Filament\Widgets\ProspectProposalsTable;
use App\Models\Appointment;
use App\Models\CallRecord;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Clicking a company from the global search bar now lands on a read-only
 * View page (details + five mini-tables) instead of jumping straight to
 * Edit — see ProspectResource::getGlobalSearchResultUrl() and
 * ViewProspect. This is deliberately a global-search-only change: every
 * other way of reaching a Prospect (the Database list's own row actions)
 * is untouched.
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
        $prospect = Prospect::factory()->create(['company_name' => 'Acme Textiles']);

        $this->actingAs($admin);

        // The details section is still ProspectResource::form() rendered
        // disabled (unchanged) — its field values are bound via Alpine/
        // Livewire entanglement rather than plain server-rendered text
        // nodes, so asserting against the form's own state (what the
        // browser will hydrate from) is the correct check here, not
        // scraping rendered HTML for the company name.
        Livewire::test(ViewProspect::class, ['record' => $prospect->getRouteKey()])
            ->assertActionVisible('edit')
            ->assertFormSet(['company_name' => 'Acme Textiles']);
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
}
