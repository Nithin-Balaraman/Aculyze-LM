<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two related reliability banners (offline detection + unmistakable
 * save-failure feedback), registered panel-wide via AdminPanelProvider's
 * ->renderHook(PanelsRenderHook::SCRIPTS_AFTER, ...) — same mechanism as
 * the existing bulk-select-store script (see BulkSelectToggleTest).
 *
 * The actual client-side behavior can't be meaningfully exercised by a
 * PHP test harness, mirroring the same "config vs. client-side behavior"
 * split already used for the collapsible sidebar (see
 * SidebarCollapseConfigTest) — this file only guards the plain PHP/config
 * facts: the render hook fires and the markup/script it produces is
 * correct, including the config('app.debug') gating.
 *
 * That said, the mechanism itself WAS verified end-to-end in a real
 * headless-Chromium session while building this (not just static
 * review): toggling a browser context's online/offline state correctly
 * showed/hid the offline banner; intercepting /livewire/update to abort
 * mid-request (simulating a dropped connection) correctly showed the
 * save-failure banner with the network-failure copy, for both a plain
 * reactive property change AND a real wire:click table action (Follow-
 * Up's "Completed" — the same action from the original bug report);
 * clicking Retry correctly resubmitted and the banner cleared once the
 * interception was lifted. One nuance surfaced by that testing worth
 * flagging: for a Filament action that opens a confirmation/fill-in
 * form before actually executing (like Follow-Up's "Completed", which
 * requires picking a Call Outcome first), a failure at the *mount*
 * step's Retry only reopens that form — it doesn't blindly resubmit an
 * unfilled form on the user's behalf, since Retry replays exactly the
 * failed request's own calls, nothing more.
 *
 * Still worth a manual check from a real user, since a scripted browser
 * session isn't the same as an actual person's environment: the visual
 * placement/readability of both banners on real content (not just the
 * dashboard this was checked against), the raw Laravel debug page still
 * showing locally (this was checked with config('app.debug') asserted
 * via PHP, but not by actually triggering a real 500 in the browser with
 * APP_DEBUG=true), and general "does this feel right" polish.
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
}
