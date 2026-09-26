<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\ProfileSentStatus;
use App\Filament\Pages\PipelineBoard;
use App\Models\CallRecord;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pipeline Board V2 (Calls column, locked design sections F1/F2/F10):
 * proves the Calls card's collapsed/expanded content and drag-destination
 * eligibility are computed correctly, and that "Attempt Count" (explicitly
 * removed by the locked design) never reappears.
 */
class PipelineBoardCallCardContentTest extends TestCase
{
    use RefreshDatabase;

    private function callLaneCards(): array
    {
        $method = new \ReflectionMethod(PipelineBoard::class, 'callLane');
        $method->setAccessible(true);

        return $method->invoke(app(PipelineBoard::class))['cards'];
    }

    public function test_a_calls_card_is_only_a_valid_destination_for_follow_up_appointment_and_lead(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            CallRecord::create([
                'prospect_id' => $prospect->id, 'user_id' => $user->id,
                'called_at' => now(), 'outcome' => CallOutcome::NoAnswer,
            ]);

            $card = $this->callLaneCards()[0];

            $this->assertSame(['follow_up', 'appointment', 'lead'], $card['validDestinations']);
        });
    }

    public function test_existing_opportunities_count_is_count_only_and_never_names_a_lead(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            CallRecord::create([
                'prospect_id' => $prospect->id, 'user_id' => $user->id,
                'called_at' => now(), 'outcome' => CallOutcome::NoAnswer,
            ]);

            Lead::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'stage' => LeadStage::RequirementCollection, 'status' => LeadStatus::RequirementCollection,
                'temperature' => LeadTemperature::Warm, 'opportunity_title' => 'Inventory Automation',
            ]);
            Lead::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'stage' => LeadStage::RequirementCollection, 'status' => LeadStatus::RequirementCollection,
                'temperature' => LeadTemperature::Warm, 'opportunity_title' => 'ERP Requirement',
            ]);

            $card = $this->callLaneCards()[0];
            $opportunities = collect($card['details'])->firstWhere('label', 'Existing Opportunities');

            $this->assertSame('2', $opportunities['value']);
            $this->assertStringNotContainsString('Inventory Automation', json_encode($card));
            $this->assertStringNotContainsString('ERP Requirement', json_encode($card));
        });
    }

    public function test_upcoming_follow_up_is_shown_in_expanded_details(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            CallRecord::create([
                'prospect_id' => $prospect->id, 'user_id' => $user->id,
                'called_at' => now(), 'outcome' => CallOutcome::NoAnswer,
            ]);
            FollowUp::create([
                'prospect_id' => $prospect->id, 'user_id' => $user->id,
                'follow_up_at' => now()->addDays(3), 'reason' => 'Callback requested', 'status' => 'pending',
            ]);

            $card = $this->callLaneCards()[0];
            $upcoming = collect($card['details'])->firstWhere('label', 'Upcoming');

            $this->assertNotNull($upcoming);
            $this->assertStringContainsString('Follow-Up', $upcoming['value']);
        });
    }

    public function test_collapsed_details_show_contact_and_phone_and_card_carries_no_attempt_count(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            CallRecord::create([
                'prospect_id' => $prospect->id, 'user_id' => $user->id,
                'called_at' => now(), 'outcome' => CallOutcome::ProfileRequested,
                'contact_person_spoken_to' => 'Anita Rao', 'designation' => 'Manager', 'phone_called' => '9999900000',
                'profile_sent_status' => ProfileSentStatus::Sent,
                'profile_sent_at' => now(),
                'profile_sent_mode' => \App\Enums\ProfileSentMode::Email,
                'notes' => 'Sent the profile over email.',
            ]);

            $card = $this->callLaneCards()[0];
            $collapsed = collect($card['collapsedDetails']);

            $this->assertSame('Anita Rao', $collapsed->firstWhere('label', 'Contact')['value']);
            $this->assertSame('9999900000', $collapsed->firstWhere('label', 'Phone')['value']);

            $this->assertArrayNotHasKey('attemptCount', $card);
            $this->assertStringNotContainsString('Attempt Count', json_encode($card));

            // Two distinct badges: main outcome + profile sub-state.
            $labels = collect($card['badges'])->pluck('label');
            $this->assertTrue($labels->contains('Profile Requested'));
            $this->assertTrue($labels->contains('Sent'));
        });
    }

    public function test_calls_card_quick_actions_are_exactly_view_edit_and_record_new_call(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            CallRecord::create([
                'prospect_id' => $prospect->id, 'user_id' => $user->id,
                'called_at' => now(), 'outcome' => CallOutcome::NoAnswer,
            ]);

            $card = $this->callLaneCards()[0];
            $labels = collect($card['quickActions'])->pluck('label')->all();

            $this->assertSame(['View Call', 'Edit Call', 'Record New Call'], $labels);

            // No duplicate Create Follow-Up/Appointment/Lead quick actions.
            foreach (['Create Follow-Up', 'Create Appointment', 'Create Lead'] as $forbidden) {
                $this->assertFalse(in_array($forbidden, $labels, true));
            }
        });
    }

    /**
     * Manual browser validation defect fix: drag-start highlighting was
     * previously wired through a `get dragState()` accessor defined inside
     * a lane's own x-data, read bare (`dragState`) from a DIFFERENT
     * directive's (:class) expression — this did not reliably re-trigger
     * that binding when the global drag store mutated, confirmed
     * empirically (dispatching a real `dragstart` correctly set
     * $store.pipelineBoard.dragActive/dragValidDestinations, but no lane's
     * class list changed until that SAME lane's own `over` flag flipped via
     * a native dragover/dragleave). Fixed by reading the store directly
     * inside each bound expression via plain methods (isValidDestination()/
     * isInvalidDestination()), called explicitly from within :class/x-on's
     * own evaluated strings rather than through an intermediary getter.
     *
     * PHPUnit can't execute real Alpine reactivity, so this is a markup-
     * level regression guard: it asserts the rendered lane wiring reads the
     * store directly inside the :class expression (not via a bare
     * `dragState` property reference), and that the highlight/dimmed
     * classes are gated on the store-derived state alone — never on `over`
     * — so a drag-start highlight can never again be silently downgraded
     * to "only shows once you're already hovering that exact lane".
     */
    public function test_lane_highlight_and_dimmed_classes_are_driven_by_the_store_not_only_by_hover(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            CallRecord::create([
                'prospect_id' => $prospect->id, 'user_id' => $user->id,
                'called_at' => now(), 'outcome' => CallOutcome::NoAnswer,
            ]);

            $html = Livewire::test(PipelineBoard::class)->html();

            // The highlight/dimmed classes must be reachable WITHOUT `over`
            // being true — i.e. gated on the store state alone, not
            // "over && ...".
            $this->assertStringContainsString("'pipeline-board-lane-highlight': ! over && isValidDestination()", $html);
            $this->assertStringContainsString("'pipeline-board-lane-dimmed': ! over && isInvalidDestination()", $html);

            // Regression guard against reintroducing the broken pattern:
            // the store is read from inside a plain method (called with
            // parens), not a lazily-bound getter — no bare `dragState`
            // property reference inside the actual :class binding (a
            // code comment elsewhere may still mention the retired name
            // for history, so this checks the binding itself, not the
            // whole page).
            $this->assertStringNotContainsString("=> dragState !== 'invalid'", $html);
            $this->assertStringNotContainsString("dragState === 'valid'", $html);
            $this->assertStringContainsString('isValidDestination()', $html);
            $this->assertStringContainsString('isInvalidDestination()', $html);

            // The Call card's dragstart handler carries the real,
            // server-computed destination list (not a placeholder) —
            // proves the data the store-driven highlight depends on is
            // actually present at drag start. Blade\Illuminate\Support\Js::from()
            // renders this as a JSON.parse('...') call with every double
            // quote escaped to " (confirmed against the actual
            // rendered markup), not a literal JS array/string.
            $escapedQuote = chr(92).'u0022';
            $this->assertStringContainsString(
                '$store.pipelineBoard.dragValidDestinations = JSON.parse(\'['
                .$escapedQuote.'follow_up'.$escapedQuote.','
                .$escapedQuote.'appointment'.$escapedQuote.','
                .$escapedQuote.'lead'.$escapedQuote.']\')',
                $html,
            );
        });
    }
}
