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

    /**
     * Returns the substring of $html covering one card's whole rendered
     * `<a>...</a>` block, found via its own `data-card` marker. Starts from
     * the nearest preceding `<a` (not from the marker itself) since
     * x-data/x-on attributes on that same tag are written BEFORE `data-card`
     * in the template — starting at the marker itself would silently cut
     * off assertions against those earlier attributes.
     */
    private function cardBlock(string $html, string $marker): string
    {
        $markerPos = strpos($html, $marker);
        $this->assertNotFalse($markerPos, "Expected to find {$marker} in the rendered board.");

        preg_match_all('/<a[\s>]/', substr($html, 0, $markerPos), $matches, PREG_OFFSET_CAPTURE);
        $this->assertNotEmpty($matches[0], "Expected an <a ...> tag before {$marker}.");
        $start = end($matches[0])[1];

        $nextCardPos = strpos($html, 'data-card="', $markerPos + strlen($marker));

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
            $this->assertStringContainsString('x-on:click.stop.prevent="toggle()"', $block);
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
                '/x-on:click\.stop\.prevent="toggle\(\)"/',
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

    public function test_the_dedicated_drag_handle_icon_is_gone_but_the_whole_card_is_still_draggable_and_click_to_open_modal_wiring_is_unchanged(): void
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

            // The dedicated corner icon is gone...
            $this->assertStringNotContainsString('pipeline-board-card-drag-handle', $block);
            $this->assertStringNotContainsString('⠿⠿', $block);
            // ...but the card itself — the <a> this whole block starts
            // with — is still the drag source, exactly as before.
            $this->assertStringContainsString('draggable="true"', $block);
            $this->assertStringContainsString("dataTransfer.setData('text/plain', JSON.stringify({ resource: 'lead', id: {$lead->id} }))", $block);
            $this->assertStringContainsString("\$wire.mountAction('cardHistory', { resource: 'lead', id: {$lead->id} })", $block);
        });
    }

    public function test_a_board_level_collapse_all_control_exists_and_is_wired_to_a_presentation_only_window_event(): void
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

            // The board-level control exists, dispatches a plain window
            // event (no wire:click / mountAction — purely presentation),
            // and disables itself when nothing is expanded.
            $this->assertStringContainsString('pipeline-board-collapse-all', $html);
            $this->assertStringContainsString("\$dispatch('pipeline-board-collapse-all')", $html);
            $this->assertStringContainsString(':disabled="$store.pipelineBoard.expandedCount === 0"', $html);

            // Every card listens for that exact event and only acts if it
            // is currently expanded — never a server round-trip.
            $block = $this->cardBlock($html, 'data-card="lead-'.$lead->id.'"');
            $this->assertStringContainsString("x-on:pipeline-board-collapse-all.window=\"collapse()\"", $block);
            $this->assertStringNotContainsString('wire:click', $block);
        });
    }

    /**
     * Final visual polish pass, Section 7: the title and the Next/date row
     * must wrap rather than hard-truncate with an ellipsis, so a real
     * scheduled date/time is never clipped. This only proves the wrapping
     * CSS classes are used (and the old clipping ones are gone) on those
     * specific elements — the visual result itself is confirmed by browser
     * QA, not asserted here.
     */
    public function test_the_next_row_and_title_wrap_instead_of_being_hard_truncated(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create([
                'assigned_to' => $user->id, 'created_by' => $user->id,
                'company_name' => 'A Genuinely Very Long Prospect Company Name Pvt Ltd',
            ]);
            $lead = Lead::create([
                'prospect_id' => $prospect->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
                'stage' => 'requirement_collection', 'temperature' => 'warm',
            ]);

            $html = Livewire::test(PipelineBoard::class)->html();
            $block = $this->cardBlock($html, 'data-card="lead-'.$lead->id.'"');

            // The full long company name is present in the title block
            // (proving it isn't cut down to a truncated prefix), and the
            // title/Next elements use wrapping classes, not `truncate`.
            $this->assertStringContainsString(
                'pipeline-board-card-title line-clamp-2 break-words',
                $block,
            );
            $this->assertStringContainsString('A Genuinely Very Long Prospect Company Name Pvt Ltd', $block);

            // The Next row's own value span no longer clips its text.
            $this->assertMatchesRegularExpression(
                '/Next:<\/span>\s*<span class="break-words">/',
                $block,
            );
        });
    }
}
