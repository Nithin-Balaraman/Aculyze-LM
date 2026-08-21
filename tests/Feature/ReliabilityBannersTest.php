<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two related reliability banners (offline detection + unmistakable
 * save-failure feedback). Markup lives in
 * resources/views/filament/reliability-banners.blade.php, registered on
 * PanelsRenderHook::CONTENT_START (inside <main>, ahead of the page's own
 * heading); the controlling script lives in
 * resources/views/filament/scripts/reliability-banners.blade.php,
 * registered on PanelsRenderHook::SCRIPTS_AFTER — same mechanism as the
 * existing bulk-select-store script (see BulkSelectToggleTest), split
 * across two hooks specifically to fix a real overlap bug: the markup
 * used to be position: fixed on SCRIPTS_AFTER alone, which overlaid the
 * page's own heading (e.g. "Follow Ups") instead of pushing it down.
 * CONTENT_START renders in normal document flow ahead of the heading, so
 * a shown banner just adds height above it, like any other element.
 *
 * The actual client-side behavior can't be meaningfully exercised by a
 * PHP test harness, mirroring the same "config vs. client-side behavior"
 * split already used for the collapsible sidebar (see
 * SidebarCollapseConfigTest) — this file only guards the plain PHP/config
 * facts: both render hooks fire, the markup/script they produce is
 * correct (including the config('app.debug') gating), the banner markup
 * precedes the page heading in the raw HTML (the actual overlap fix),
 * and it's no longer position: fixed.
 *
 * That said, the mechanism itself WAS verified end-to-end in a real
 * headless-Chromium session while building this (not just static
 * review): toggling a browser context's online/offline state correctly
 * showed/hid the offline banner (confirmed the banner box and the page
 * heading's box no longer overlap after this fix, and confirmed no
 * client-side error on the login page, which uses a simpler layout
 * without the CONTENT_START markup but still loads the SCRIPTS_AFTER
 * script — every DOM lookup there is null-guarded because of this);
 * intercepting /livewire/update to abort mid-request (simulating a
 * dropped connection) correctly showed the save-failure banner with the
 * network-failure copy, for both a plain reactive property change AND a
 * real wire:click table action (Follow-Up's "Completed" — the same
 * action from the original bug report); clicking Retry correctly
 * resubmitted and the banner cleared once the interception was lifted.
 * One nuance surfaced by that testing worth flagging: for a Filament
 * action that opens a confirmation/fill-in form before actually
 * executing (like Follow-Up's "Completed", which requires picking a
 * Call Outcome first), a failure at the *mount* step's Retry only
 * reopens that form — it doesn't blindly resubmit an unfilled form on
 * the user's behalf, since Retry replays exactly the failed request's
 * own calls, nothing more.
 *
 * On the offline banner specifically: Chrome DevTools' Network-panel
 * "Offline" throttling preset is not a reliable way to test this part —
 * it's meant primarily for exercising request-level failure handling
 * (which is what it correctly does for the save-failure banner above),
 * and does not consistently fire the browser's actual online/offline
 * events across environments (this app's headless-Chromium build did
 * fire them via the equivalent Playwright API, but that isn't a
 * guarantee DevTools' own UI preset behaves identically everywhere).
 * The two actually-reliable ways to check this by hand: physically
 * toggling real connectivity (Wi-Fi off/airplane mode), or — to test
 * this banner's own logic directly, independent of that ambiguity —
 * running `window.dispatchEvent(new Event('offline'))` /
 * `window.dispatchEvent(new Event('online'))` directly in the browser's
 * console, which was confirmed here to toggle the banner correctly.
 *
 * Still worth a manual check from a real user, since a scripted browser
 * session isn't the same as an actual person's environment: the visual
 * placement/readability of both banners on real content beyond what was
 * checked here, the raw Laravel debug page still showing locally (this
 * was checked with config('app.debug') asserted via PHP, but not by
 * actually triggering a real 500 in the browser with APP_DEBUG=true),
 * and general "does this feel right" polish.
 */
class ReliabilityBannersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_offline_banner_is_rendered_on_an_authenticated_page(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/admin');

        $response->assertOk();
        $response->assertSee('id="fi-offline-banner"', false);
        $response->assertSee("You&rsquo;re offline &mdash; changes won&rsquo;t save until connection is restored.", false);
        $response->assertSee("window.addEventListener('offline'", false);
        $response->assertSee("window.addEventListener('online'", false);
    }

    public function test_the_save_failure_banner_markup_is_rendered(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/admin');

        $response->assertOk();
        $response->assertSee('id="fi-save-failure-banner"', false);
        $response->assertSee('id="fi-save-failure-banner-retry"', false);
        $response->assertSee('id="fi-save-failure-banner-dismiss"', false);
        $response->assertSee('Save failed &mdash; please try again.', false);
    }

    public function test_the_request_failure_hooks_are_registered(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/admin');

        $response->assertOk();
        $response->assertSee("Livewire.hook('commit'", false);
        $response->assertSee("Livewire.hook('request'", false);
        // The Retry mechanism itself, and its fallback for a failed
        // commit with no action calls to replay.
        $response->assertSee('component.$wire.$call(method, ...params)', false);
        $response->assertSee('component.$wire.$commit()', false);
    }

    public function test_debug_mode_gating_reflects_config_when_debug_is_enabled(): void
    {
        config(['app.debug' => true]);

        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/admin');

        $response->assertOk();
        $response->assertSee('const isDebugMode = true;', false);
    }

    public function test_debug_mode_gating_reflects_config_when_debug_is_disabled(): void
    {
        config(['app.debug' => false]);

        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/admin');

        $response->assertOk();
        $response->assertSee('const isDebugMode = false;', false);
    }

    /**
     * The 419 (session/CSRF expired) status is deliberately excluded from
     * the preventDefault()/banner override — Livewire's own "reload?"
     * confirm() dialog is left as the correct fix there, not a Retry of
     * an already-expired request.
     */
    public function test_419_status_is_left_to_livewires_own_handling(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/admin');

        $response->assertOk();
        $response->assertSee('if (status === 419) {', false);
    }

    /**
     * Regression guard for the reported overlap bug: the banner markup
     * used to be position: fixed on SCRIPTS_AFTER alone, rendering it on
     * top of the page's own heading instead of pushing it down. Now that
     * the markup is on CONTENT_START, it must appear *before* the page
     * heading in the raw HTML — assertSeeInOrder fails if that ordering
     * ever regresses.
     */
    public function test_the_offline_banner_precedes_the_page_heading_in_the_response(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/admin');

        $response->assertOk();
        $response->assertSeeInOrder(['id="fi-offline-banner"', 'My Dashboard'], false);
    }

    /**
     * Companion regression guard: the fixed-position styling itself
     * (fixed / inset-x-0 / top-0 / z-[70] Tailwind utilities) must not
     * reappear on the banners' own wrapper div specifically, since that's
     * what caused the overlap in the first place. Scoped to the wrapper's
     * own class attribute (rather than a page-wide substring check)
     * because Filament's own chrome (e.g. the topbar) legitimately uses
     * class="fixed" elsewhere on the page.
     */
    public function test_the_banner_wrapper_is_not_position_fixed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/admin');

        $response->assertOk();

        preg_match('/<div class="([^"]*)">\s*<div\s+id="fi-offline-banner"/', $response->getContent(), $matches);

        $this->assertNotEmpty($matches, 'Could not locate the reliability banners wrapper div in the response.');
        $this->assertStringNotContainsString('fixed', $matches[1]);
    }
}
