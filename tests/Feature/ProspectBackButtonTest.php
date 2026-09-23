<?php

namespace Tests\Feature;

use App\Filament\Resources\ProspectResource;
use App\Filament\Resources\ProspectResource\Pages\EditProspect;
use App\Filament\Resources\ProspectResource\Pages\ViewProspect;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Multi-hop navigation bug fix: the Back action's server-computed
 * url()->previous() (the original implementation — see git history)
 * correctly returned to wherever the user came from for exactly ONE hop,
 * then ping-ponged between two pages forever. previous() only ever tracks
 * a single last-URL slot, not a real stack, and each Back click is itself
 * a new page load that immediately overwrites that slot with the page
 * just left — confirmed by tracing an actual List -> View -> Edit ->
 * Back -> Back sequence step by step against Illuminate\Session\
 * Middleware\StartSession's own storeCurrentUrl() timing.
 *
 * Fixed by moving the *primary* navigation to the browser's own real
 * history.back() (a Closure-string click handler, ProspectResource::
 * BACK_BUTTON_CLICK_HANDLER, attached via ->extraAttributes()), which
 * correctly walks back through the real, arbitrarily-deep sequence of
 * pages visited — exactly like a normal browser back button, because it
 * IS the browser's own back mechanism. ->url() now only supplies the
 * fallback destination (the Prospects list) for when there's truly no
 * browser history (history.length === 1: a fresh tab/bookmark) or
 * JavaScript is unavailable — a plain server-rendered link, no history
 * awareness needed for that case.
 *
 * PHPUnit/Livewire testing cannot exercise real multi-tab browser
 * history — the same limitation already documented in
 * CallRecordCreateCompanyInlineTest's own docblock for Alpine-triggered
 * clicks. What's testable and asserted here: the action's color, its
 * fallback ->url(), and that the exact history.back() click handler is
 * actually attached. The real multi-hop sequence (List -> View -> Edit ->
 * Back -> Back -> Back landing on Edit, then View, then List) is verified
 * separately via real-browser Playwright QA — see the task's own report.
 */
class ProspectBackButtonTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        return $admin;
    }

    public function test_view_page_back_button_falls_back_to_the_database_list(): void
    {
        $this->actingAdmin();
        $prospect = Prospect::factory()->create();
        $listUrl = ProspectResource::getUrl('index');

        Livewire::test(ViewProspect::class, ['record' => $prospect->getRouteKey()])
            ->assertActionHasUrl('back', $listUrl);
    }

    public function test_edit_page_back_button_falls_back_to_the_database_list(): void
    {
        $this->actingAdmin();
        $prospect = Prospect::factory()->create();
        $listUrl = ProspectResource::getUrl('index');

        Livewire::test(EditProspect::class, ['record' => $prospect->getRouteKey()])
            ->assertActionHasUrl('back', $listUrl);
    }

    /**
     * The actual client-side navigation can't be exercised here (no real
     * browser), but the handler being attached to the action is directly
     * verifiable — this is what makes history.back() actually fire on a
     * real click, verified separately in the browser. Compared by
     * substring rather than exact string equality: ComponentAttributeBag::
     * merge() (Blade's own attribute-merging, which getExtraAttributes()
     * runs every value through) HTML-escapes string values as a matter of
     * course — harmless here since browsers decode HTML entities in an
     * attribute's value before Alpine ever reads it, but it means the raw
     * getter never returns the handler byte-for-byte unescaped.
     */
    public function test_view_page_back_button_has_the_history_back_click_handler(): void
    {
        $this->actingAdmin();
        $prospect = Prospect::factory()->create();

        $action = Livewire::test(ViewProspect::class, ['record' => $prospect->getRouteKey()])
            ->instance()
            ->getAction('back');

        $handler = $action->getExtraAttributes()['x-on:click'] ?? '';
        $this->assertStringContainsString('window.history.length', $handler);
        $this->assertStringContainsString('window.history.back();', $handler);
    }

    public function test_edit_page_back_button_has_the_history_back_click_handler(): void
    {
        $this->actingAdmin();
        $prospect = Prospect::factory()->create();

        $action = Livewire::test(EditProspect::class, ['record' => $prospect->getRouteKey()])
            ->instance()
            ->getAction('back');

        $handler = $action->getExtraAttributes()['x-on:click'] ?? '';
        $this->assertStringContainsString('window.history.length', $handler);
        $this->assertStringContainsString('window.history.back();', $handler);
    }

    /**
     * Give the Back button some color: reuses this app's own established
     * pattern for a distinct-but-secondary icon+label action (->color(
     * 'info'), the same brand cyan already used by e.g. LeadResource's
     * "Update Status"/"Schedule Demo" and ProposalResource's "Continue")
     * rather than a new, one-off color.
     */
    public function test_view_page_back_button_uses_the_apps_established_secondary_action_color(): void
    {
        $this->actingAdmin();
        $prospect = Prospect::factory()->create();

        Livewire::test(ViewProspect::class, ['record' => $prospect->getRouteKey()])
            ->assertActionHasColor('back', 'info');
    }

    public function test_edit_page_back_button_uses_the_apps_established_secondary_action_color(): void
    {
        $this->actingAdmin();
        $prospect = Prospect::factory()->create();

        Livewire::test(EditProspect::class, ['record' => $prospect->getRouteKey()])
            ->assertActionHasColor('back', 'info');
    }
}
