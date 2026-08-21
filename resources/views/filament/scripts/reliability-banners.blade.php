{{--
    Behavior for the two reliability banners whose markup lives in
    resources/views/filament/reliability-banners.blade.php (registered
    separately, on PanelsRenderHook::CONTENT_START — see that file for
    why the markup and this script are deliberately split across two
    render hooks). Registered once, panel-wide, via AdminPanelProvider on
    PanelsRenderHook::SCRIPTS_AFTER — same pattern as the existing
    bulk-select-store script.

    A) Offline banner: reacts to the browser's own native online/offline
    events. Livewire ships a wire:offline *directive* that does exactly
    this — but directives are only ever processed within a Livewire
    component's own DOM tree (rooted at its wire:id element), and this
    banner is injected via a panel-level render hook, outside any
    component boundary — confirmed empirically (a real browser session:
    the directive attribute was present in the DOM, but toggling
    connectivity, and even manually dispatching a native 'offline' event,
    never changed its display). A plain window.addEventListener('offline'
    | 'online', ...) below has no such DOM-boundary requirement and is
    what actually works here. It only reacts to future online<->offline
    transitions, not the page's state at initial load — a native-API
    limitation either way, not something worked around here.

    B) Save-failure banner: Livewire.hook('request', ...) fires on every
    failed request — including a network-level fetch() failure (the
    connection drops mid-request), which Livewire's own default behavior
    handles by doing absolutely nothing visible (see the try/catch in
    vendor/livewire/livewire/js/request/index.js's sendRequest() — it
    calls the internal fail() callbacks but never shows any modal or
    banner). Nothing in this app listened to that hook before, so a
    network-level save failure was completely silent — almost certainly
    what let a Follow-Up completion go unnoticed. A real HTTP error
    response (4xx/5xx) DOES already show something by default: Livewire's
    own raw-HTML failure modal (Laravel's generic error page in
    production). preventDefault() inside the 'request' hook's fail()
    suppresses that modal — but only in production (config('app.debug')
    false); local dev keeps the real debug page so exceptions are still
    visible while developing. 419 (session/CSRF expired) is deliberately
    left alone: Livewire's own "reload?" confirm() dialog is the correct
    fix there, not a Retry of an already-expired request.

    Livewire.hook('commit', ...) is a *separate*, per-component hook that
    additionally exposes exactly which method+params were being called
    (commit.calls) — used here only to remember what Retry should
    resubmit, not to decide what the banner shows. Per
    vendor/livewire/livewire/js/request/{bus,commit,pool}.js, a commit's
    own fail() callback is invoked (synchronously, from the very same
    failed request) *before* the 'request' hook's fail() runs, so by the
    time the banner's copy/behavior is decided, the retry target is
    already recorded — no cross-request correlation is needed. Retry
    replays the exact method(s) via component.$wire.$call(method,
    ...params) (the same underlying call Livewire's own action-triggering
    directives use), falling back to a plain component.$wire.$commit()
    if the failed commit had no calls at all (e.g. a plain reactive
    property sync, not an action click).
--}}
<script>
    // The markup (resources/views/filament/reliability-banners.blade.php)
    // only renders on pages using the main app layout — this script,
    // registered on SCRIPTS_AFTER, additionally reaches auth pages
    // (login, etc.) that use a simpler layout without it, so every DOM
    // lookup here is null-guarded rather than assuming the elements
    // exist.
    window.addEventListener('offline', () => {
        document.getElementById('fi-offline-banner')?.style.setProperty('display', 'flex');
    });

    window.addEventListener('online', () => {
        document.getElementById('fi-offline-banner')?.style.setProperty('display', 'none');
    });

    document.addEventListener('livewire:init', () => {
        const isDebugMode = @js((bool) config('app.debug'));

        let lastFailedCommit = null;

        const banner = () => document.getElementById('fi-save-failure-banner');

        const showSaveFailureBanner = (message) => {
            const bannerEl = banner();
            const messageEl = document.getElementById('fi-save-failure-banner-message');

            if (! bannerEl || ! messageEl) return;

            messageEl.textContent = message;
            bannerEl.style.display = 'flex';
        };

        const hideSaveFailureBanner = () => {
            banner()?.style.setProperty('display', 'none');
        };

        document.getElementById('fi-save-failure-banner-dismiss')?.addEventListener('click', hideSaveFailureBanner);

        document.getElementById('fi-save-failure-banner-retry')?.addEventListener('click', () => {
            hideSaveFailureBanner();

            if (! lastFailedCommit) return;

            const { component, calls } = lastFailedCommit;

            if (calls.length > 0) {
                calls.forEach(({ method, params }) => component.$wire.$call(method, ...params));
            } else {
                component.$wire.$commit();
            }
        });

        Livewire.hook('commit', ({ component, commit, fail }) => {
            fail(() => {
                lastFailedCommit = { component, calls: commit.calls };
            });
        });

        Livewire.hook('request', ({ fail }) => {
            fail(({ status, content, preventDefault }) => {
                // Livewire's own synthesized signature for a fetch()-level
                // failure (connection dropped mid-request) — no real HTTP
                // response was ever received, so there's no raw-HTML modal
                // to suppress; Livewire wouldn't have shown one anyway.
                let isNetworkFailure = status === 503 && content === null;

                if (isNetworkFailure) {
                    showSaveFailureBanner('Save failed — please check your connection and try again.');

                    return;
                }

                if (status === 419) {
                    return;
                }

                if (! isDebugMode) {
                    preventDefault();
                    showSaveFailureBanner('Save failed — please try again.');
                }
            });
        });
    });
</script>
