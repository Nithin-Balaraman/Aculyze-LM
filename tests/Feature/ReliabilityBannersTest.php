<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reliability notifications: an offline notice (reacting to the browser's
 * native online/offline events) and an unmistakable, manually-dismissed
 * save-failure notice with a Retry button. Both reuse Filament's own
 * "Saved"-toast mechanism — registered panel-wide via AdminPanelProvider
 * on PanelsRenderHook::SCRIPTS_AFTER — rather than a custom banner.
 *
 * This is the second implementation of this feature. The first
 * (see git history — commit 96b898c and its immediate follow-up) was a
 * bespoke fixed-position <div>; manual browser testing surfaced two real
 * bugs (it overlapped the page's own heading, and required splitting
 * across two render hooks to fix, which then broke the login page's
 * simpler layout). Rather than patch that further, this rewrite drops the
 * custom banner entirely and reuses Filament's own notification
 * container/Alpine component instead — the exact thing that already,
 * reliably, shows the "Saved" toast regardless of scroll position or
 * page layout, so there's no positioning code of ours left to get wrong.
 *
 * Why this can't simply be Notification::make()->send() (or its pure-JS
 * equivalent, `new FilamentNotification().send()`,
 * vendor/filament/notifications/resources/js/Notification.js) — confirmed
 * empirically, not just by reading source: both ultimately dispatch a
 * browser event that Livewire's own client runtime turns into a call to
 * the `Notifications` Livewire component
 * (vendor/filament/notifications/src/Livewire/Notifications.php), and
 * calling into a Livewire component always requires an actual request to
 * /livewire/update (component.$wire.call('__dispatch', ...) in
 * vendor/livewire/livewire/js/features/supportListeners.js). Tested this
 * directly in a real headless-Chromium session: with the browser context
 * genuinely offline, `new FilamentNotification().send()` fires exactly
 * that request, it fails with ERR_INTERNET_DISCONNECTED, and the toast
 * never appears — not even after reconnecting. That's fatal for an
 * offline indicator specifically (the one moment it needs to work is
 * exactly the moment that path is broken), and for the network-drop-
 * mid-save case (the other failure modes — a real 4xx/5xx response, or
 * 419 — did complete a round trip while still online, so triggering a
 * notification for those doesn't have this problem).
 *
 * The fix: two hidden <template>s (in
 * resources/views/filament/scripts/reliability-notifications.blade.php)
 * that mirror Filament's own notification markup/classes closely (reusing
 * its real <x-filament-notifications::{icon,title,body,close-button}>
 * Blade components directly, so styling tracks Filament's own rather than
 * drifting out of sync with hand-copied Tailwind classes), inert until a
 * plain window.addEventListener('offline'|'online', ...) or
 * Livewire.hook('request'|'commit', ...) clones one, sets its message,
 * and calls window.Alpine.initTree(clonedEl) to bind it — Alpine's own
 * "notificationComponent" (vendor/filament/notifications/resources/js/
 * components/notification.js) then drives its show/hide animation and
 * persistent-until-manually-closed behavior, exactly like a real toast,
 * with the clone dropped straight into Filament's own notification
 * container (.fi-no) — no network call anywhere in this path.
 *
 * Verified live in a real headless-Chromium session (not just static
 * review): logging in, then toggling the browser context genuinely
 * offline (context.setOffline(true), which fires real online/offline
 * events, not just DevTools' request-level throttling) shows the warning-
 * colored "You're offline" toast in the exact same top-right corner/style
 * as a native Filament toast, and it auto-closes on reconnect. Aborting
 * /livewire/update mid-request (simulating a dropped connection during a
 * save) shows the danger-colored "Save failed" toast with a Retry button;
 * clicking Retry after restoring the connection resubmits the exact
 * failed call and the toast clears. The login page (simpler layout, no
 * .fi-no container rendered) loads with zero console/page errors, since
 * every DOM lookup in the script is null-guarded.
 *
 * The actual client-side behavior can't be meaningfully exercised by a
 * PHP test harness — this file only guards the plain PHP/config facts:
 * the render hook fires, the two <template>s and the controlling script
 * are present with the right ids/classes/copy, the debug-mode gating
 * behaves correctly, and 419 is left to Livewire's own handling. The
 * real-browser behavior above needs a genuine manual pass to re-confirm
 * after any further change to this file.
 */
class ReliabilityBannersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_offline_notification_template_is_rendered(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/admin');

        $response->assertOk();
        $response->assertSee('id="fi-reliability-offline-template"', false);
        $response->assertSee("notification: { id: 'fi-reliability-offline', duration: 'persistent' }", false);
        $response->assertSee('You&rsquo;re offline', false);
        $response->assertSee('Changes won&rsquo;t save until connection is restored.', false);
        $response->assertSee("window.addEventListener('offline'", false);
        $response->assertSee("window.addEventListener('online'", false);
    }

    public function test_the_save_failure_notification_template_is_rendered(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/admin');

        $response->assertOk();
        $response->assertSee('id="fi-reliability-save-failure-template"', false);
        $response->assertSee("notification: { id: 'fi-reliability-save-failure', duration: 'persistent' }", false);
        $response->assertSee('id="fi-reliability-save-failure-retry"', false);
        $response->assertSee('id="fi-reliability-save-failure-message"', false);
        $response->assertSee('Save failed', false);
    }

    /**
     * The offline notification must not be manually dismissible — it
     * should only disappear once the 'online' event actually fires (see
     * closeReliabilityNotification('fi-reliability-offline') in the
     * script). The save-failure notification is the opposite: it keeps
     * its close button, since that one *is* meant to be dismissible by
     * hand (in addition to Retry).
     */
    public function test_only_the_offline_notification_has_no_close_button(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/admin');
        $response->assertOk();

        $html = $response->getContent();

        $offlineTemplate = $this->extractTemplate($html, 'fi-reliability-offline-template');
        $saveFailureTemplate = $this->extractTemplate($html, 'fi-reliability-save-failure-template');

        $this->assertStringNotContainsString('fi-no-notification-close-btn', $offlineTemplate);
        $this->assertStringContainsString('fi-no-notification-close-btn', $saveFailureTemplate);
    }

    private function extractTemplate(string $html, string $templateId): string
    {
        $start = strpos($html, '<template id="'.$templateId.'">');
        $this->assertNotFalse($start, "Could not find <template id=\"{$templateId}\"> in the response.");

        $end = strpos($html, '</template>', $start);
        $this->assertNotFalse($end, "Could not find the closing </template> for \"{$templateId}\".");

        return substr($html, $start, $end - $start);
    }

    public function test_notifications_are_cloned_into_filaments_own_notification_container(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/admin');

        $response->assertOk();
        // Reuses Filament's own notification container/Alpine component
        // rather than any positioning code of our own.
        $response->assertSee("document.querySelector('.fi-no')", false);
        $response->assertSee('window.Alpine.initTree(el)', false);
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
     * the preventDefault()/notification override — Livewire's own
     * "reload?" confirm() dialog is left as the correct fix there, not a
     * Retry of an already-expired request.
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
     * Regression guard: the login page uses a simpler layout, so this
     * script (registered on SCRIPTS_AFTER, reaching every page) must not
     * assume its own <template>s or Filament's .fi-no container exist
     * there unconditionally.
     */
    public function test_the_login_page_loads_successfully_with_the_reliability_script_present(): void
    {
        $response = $this->get('/admin/login');

        $response->assertOk();
        $response->assertSee("window.addEventListener('offline'", false);
    }
}
