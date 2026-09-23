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
     * Color, corrected: 'info' (cyan) sat too close in hue to Edit's own
     * implicit default color ('primary', steel blue — confirmed via
     * EditAction::make()->getColor() === null, so it falls through to
     * Filament's own default) to read as visually distinct at a glance.
     * 'gold' is ProspectResource::BACK_BUTTON_COLOR — a real, already-
     * registered brand token (AdminPanelProvider's colors(), #C99A3D),
     * not a one-off hex value, chosen after ruling out every other color
     * already active on this app's Filament actions: gray (the
     * established "plain secondary" choice, but rejected — would undo
     * the earlier explicit request to make Back stand out), danger/
     * success/warning (wrong semantics for harmless navigation), every
     * other blue-family token (info/primary/accent/slateblue/navy — the
     * exact problem being fixed), and coral (this app's own active
     * "Mark Lost" signal on 2 real buttons — reusing it here would
     * misleadingly suggest something negative). See
     * BACK_BUTTON_COLOR's own docblock for the full reasoning.
     */
    public function test_view_page_back_button_uses_the_chosen_distinct_color(): void
    {
        $this->actingAdmin();
        $prospect = Prospect::factory()->create();

        Livewire::test(ViewProspect::class, ['record' => $prospect->getRouteKey()])
            ->assertActionHasColor('back', ProspectResource::BACK_BUTTON_COLOR)
            ->assertActionDoesNotHaveColor('back', 'info');
    }

    public function test_edit_page_back_button_uses_the_chosen_distinct_color(): void
    {
        $this->actingAdmin();
        $prospect = Prospect::factory()->create();

        Livewire::test(EditProspect::class, ['record' => $prospect->getRouteKey()])
            ->assertActionHasColor('back', ProspectResource::BACK_BUTTON_COLOR)
            ->assertActionDoesNotHaveColor('back', 'info');
    }

    /**
     * The whole point of this change, checked directly on the page where
     * both buttons actually sit side by side: Back's color must not equal
     * whatever Edit's own (implicit-default, unset ->color() — see
     * BACK_BUTTON_COLOR's own docblock) color resolves to.
     */
    public function test_back_and_edit_buttons_do_not_share_a_color_on_the_view_page(): void
    {
        $this->actingAdmin();
        $prospect = Prospect::factory()->create();

        $test = Livewire::test(ViewProspect::class, ['record' => $prospect->getRouteKey()]);

        $backColor = $test->instance()->getAction('back')->getColor();
        $editColor = $test->instance()->getAction('edit')->getColor();

        $this->assertNotSame($backColor, $editColor);
    }
}
