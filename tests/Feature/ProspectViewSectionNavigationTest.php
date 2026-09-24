<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Filament\Resources\ProspectResource;
use App\Models\CallRecord;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * UI/navigation pass only (no business-logic change): App\Support\Filament\
 * SectionTabs as applied to ViewProspect. The page's old details-then-six-
 * stacked-mini-tables layout is now 7 tabs: Overview (Company Details,
 * expanded — see ViewProspect's own infolist() docblock for why it's no
 * longer ->collapsed()), Call Records, Follow-Ups, Appointments, Leads,
 * Demos, Proposals.
 *
 * Uses the same `tabs[<N> - 1]` fingerprint technique as
 * ViewCommercialVersionSectionNavigationTest.php — see that file's own
 * docblock for exactly why this is the correct, non-brittle way to verify
 * server-computed initial tab selection without a real browser. Unlike
 * Commercial Version, no tab here is ever hidden per viewer (confirmed
 * during this page's own investigation: none of the six activity widgets
 * carry any ->visible()/role-based gating), so there is no "hidden tab"
 * class of test to write here — every viewer who can reach this page at
 * all sees the same 7 tabs, in the same order.
 */
class ProspectViewSectionNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function url(Prospect $prospect, ?string $section = null): string
    {
        $url = ProspectResource::getUrl('view', ['record' => $prospect]);

        return $section === null ? $url : "{$url}?section={$section}";
    }

    private function activeTabIndex(TestResponse $response): ?int
    {
        preg_match('/tabs\[(\d+) - 1\]/', $response->getContent(), $matches);

        return isset($matches[1]) ? (int) $matches[1] : null;
    }

    public function test_default_tab_is_overview_expanded_with_no_click_needed(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id, 'contact_person' => 'Jane Doe']);

        $response = $this->actingAs($admin)->get($this->url($prospect));

        $this->assertSame(1, $this->activeTabIndex($response));
        // Company Details is visible in the raw response with no
        // additional interaction — proves "expanded by default", not
        // merely "present in the DOM but collapsed" (Filament's collapsed
        // infolist sections still render their content server-side, so a
        // plain assertSee alone wouldn't distinguish the two — the
        // active-tab-index check above is what actually pins this down:
        // Overview is the initially active, visible tab).
        $response->assertSee('Jane Doe');
    }

    public function test_selecting_call_records_resolves_to_its_own_position(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id]);
        CallRecord::create(['prospect_id' => $prospect->id, 'user_id' => $admin->id, 'called_at' => now(), 'outcome' => CallOutcome::AppointmentSet, 'notes' => 'Client confirmed a slot for next week.']);

        $response = $this->actingAs($admin)->get($this->url($prospect, 'prospect-view-tabs-call-records-tab'));

        $this->assertSame(2, $this->activeTabIndex($response));
        $response->assertSee('Appointment Set');
    }

    public function test_selecting_follow_ups_resolves_to_its_own_position(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id]);

        $response = $this->actingAs($admin)->get($this->url($prospect, 'prospect-view-tabs-follow-ups-tab'));

        $this->assertSame(3, $this->activeTabIndex($response));
    }

    public function test_selecting_appointments_resolves_to_its_own_position(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id]);

        $response = $this->actingAs($admin)->get($this->url($prospect, 'prospect-view-tabs-appointments-tab'));

        $this->assertSame(4, $this->activeTabIndex($response));
    }

    public function test_selecting_leads_resolves_to_its_own_position(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id]);

        $response = $this->actingAs($admin)->get($this->url($prospect, 'prospect-view-tabs-leads-tab'));

        $this->assertSame(5, $this->activeTabIndex($response));
    }

    public function test_selecting_demos_resolves_to_its_own_position(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id]);

        $response = $this->actingAs($admin)->get($this->url($prospect, 'prospect-view-tabs-demos-tab'));

        $this->assertSame(6, $this->activeTabIndex($response));
    }

    public function test_selecting_proposals_resolves_to_its_own_position(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id]);

        $response = $this->actingAs($admin)->get($this->url($prospect, 'prospect-view-tabs-proposals-tab'));

        $this->assertSame(7, $this->activeTabIndex($response));
    }

    public function test_unknown_section_key_falls_back_to_the_default_tab(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id]);

        $response = $this->actingAs($admin)->get($this->url($prospect, 'garbage-not-real'));

        $this->assertSame(1, $this->activeTabIndex($response));
    }

    public function test_header_actions_remain_available_regardless_of_selected_tab(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id]);

        $this->actingAs($admin);

        foreach ([null, 'prospect-view-tabs-proposals-tab'] as $section) {
            $this->get($this->url($prospect, $section))->assertSee('Back')->assertSee('Edit');
        }
    }

    public function test_period_employee_filters_form_is_visible_regardless_of_selected_tab(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id]);

        $this->actingAs($admin);

        foreach ([null, 'prospect-view-tabs-leads-tab'] as $section) {
            $this->get($this->url($prospect, $section))->assertSee('Period')->assertSee('Employee');
        }
    }
}
