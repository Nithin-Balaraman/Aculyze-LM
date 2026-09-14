<?php

namespace Tests\Feature;

use App\Filament\Pages\PipelineBoard;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pipeline Board card expansion pass (visual/interaction-presentation only):
 * every card gets a dedicated bottom expand/collapse control that reveals a
 * short quick-context panel INSIDE the card, entirely separate from the
 * card's own existing click-opens-modal behavior (PipelineBoard::
 * cardHistoryAction(), untouched) and its drag handle (untouched). These
 * tests only prove the markup/wiring is present and scoped correctly — the
 * visual appearance itself is verified by real browser QA (see the
 * "PIPELINE BOARD CARD EXPANSION VISUAL REPORT"), not brittle string
 * assertions on styling.
 */
class PipelineBoardCardExpanderTest extends TestCase
{
    use RefreshDatabase;

    /** Returns the substring of $html between a card's own data-card marker and the next one (or end of string), i.e. that one card's own rendered block. */
    private function cardBlock(string $html, string $marker): string
    {
        $start = strpos($html, $marker);
        $this->assertNotFalse($start, "Expected to find {$marker} in the rendered board.");

        $nextCardPos = strpos($html, 'data-card="', $start + strlen($marker));

        return $nextCardPos === false
            ? substr($html, $start)
            : substr($html, $start, $nextCardPos - $start);
    }

    public function test_the_board_still_has_exactly_six_lanes_with_no_nested_stage_containers_after_the_expander_pass(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);

            $lanes = app(PipelineBoard::class)->getLanes();
            $html = Livewire::test(PipelineBoard::class)->html();

            $this->assertSame(
                ['call', 'follow_up', 'appointment', 'lead', 'demo', 'proposal'],
                array_keys($lanes),
            );
            $this->assertStringNotContainsString('data-stage', $html);
        });
    }

    public function test_a_collapsed_card_renders_a_separate_expand_control_with_accessible_labels(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create([
                'assigned_to' => $user->id, 'created_by' => $user->id,
                'contact_person' => 'Meera Iyer', 'mobile' => '9000000000',
            ]);
            $lead = Lead::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'stage' => 'requirement_collection', 'temperature' => 'hot',
                'requirement_details' => 'Needs a bulk export workflow.',
            ]);

            $html = Livewire::test(PipelineBoard::class)->html();
            $block = $this->cardBlock($html, 'data-card="lead-'.$lead->id.'"');

            $this->assertStringContainsString('pipeline-board-expand-btn', $block);
            $this->assertStringContainsString("expanded ? 'Hide details' : 'Show more details'", $block);
            $this->assertStringContainsString('expanded = ! expanded', $block);
        });
    }

    public function test_the_expand_controls_click_handler_stops_propagation_so_it_cannot_trigger_the_card_click_modal(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $lead = Lead::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'stage' => 'requirement_collection', 'temperature' => 'warm',
            ]);

            $html = Livewire::test(PipelineBoard::class)->html();
            $block = $this->cardBlock($html, 'data-card="lead-'.$lead->id.'"');

            // The toggle button's own handler is .stop.prevent — a click on
            // it can never bubble to the enclosing <a>'s click handler.
            $this->assertMatchesRegularExpression(
                '/x-on:click\.stop\.prevent="expanded = ! expanded"/',
                $block,
            );
            // And the panel it reveals also stops propagation for any click
            // landing on its own text rows.
            $this->assertStringContainsString('x-on:click.stop', $block);
        });
    }

    public function test_expanded_details_markup_is_scoped_inside_its_own_card_and_shows_module_aware_fields(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create([
                'assigned_to' => $user->id, 'created_by' => $user->id,
                'contact_person' => 'Meera Iyer', 'mobile' => '9000000000', 'email' => 'meera@example.com',
            ]);
            $lead = Lead::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'stage' => 'requirement_collection', 'temperature' => 'hot',
                'requirement_details' => 'Needs a bulk export workflow.',
            ]);
            $otherProspect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id, 'company_name' => 'Other Co']);
            $otherLead = Lead::create([
                'prospect_id' => $otherProspect->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'stage' => 'validated', 'temperature' => 'cold', 'notes' => 'Ready for proposal.',
            ]);

            $html = Livewire::test(PipelineBoard::class)->html();
            $block = $this->cardBlock($html, 'data-card="lead-'.$lead->id.'"');
            $otherBlock = $this->cardBlock($html, 'data-card="lead-'.$otherLead->id.'"');

            $this->assertStringContainsString('pipeline-board-expanded-details', $block);
            $this->assertStringContainsString('Requirement:', $block);
            $this->assertStringContainsString('Needs a bulk export workflow.', $block);
            $this->assertStringContainsString('Meera Iyer', $block);
            $this->assertStringContainsString('Open company', $block);

            // The other card's own block never leaks this lead's details —
            // the panel is scoped to its own <a data-card="..."> only.
            $this->assertStringNotContainsString('Needs a bulk export workflow.', $otherBlock);
        });
    }

    public function test_a_draggable_cards_drag_handle_and_click_to_open_modal_wiring_are_both_still_present_alongside_the_expander(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $lead = Lead::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'stage' => 'requirement_collection', 'temperature' => 'warm',
            ]);

            $html = Livewire::test(PipelineBoard::class)->html();
            $block = $this->cardBlock($html, 'data-card="lead-'.$lead->id.'"');

            $this->assertStringContainsString('draggable="true"', $block);
            $this->assertStringContainsString('pipeline-board-card-drag-handle', $block);
            $this->assertStringContainsString("\$wire.mountAction('cardHistory', { resource: 'lead', id: {$lead->id} })", $block);
        });
    }
}
