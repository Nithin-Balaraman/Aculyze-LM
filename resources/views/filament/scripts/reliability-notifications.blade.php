@php
    // Same helper Filament's own notification component uses internally
    // (vendor/filament/notifications/resources/views/notification.blade.php)
    // to turn a color name into the CSS custom properties its Tailwind
    // classes (bg-custom-50, ring-custom-600/20, text-custom-400, ...)
    // read from — called here directly since these two <template>s are
    // plain Blade, not a Livewire component.
    $warningStyle = \Filament\Support\get_color_css_variables('warning', shades: [50, 400, 600], alias: 'notifications::notification');
    $dangerStyle = \Filament\Support\get_color_css_variables('danger', shades: [50, 400, 600], alias: 'notifications::notification');
@endphp

{{--
    Two hidden <template>s, cloned and shown by the script below entirely
    client-side — no Livewire round-trip, no custom banner. Previous
    rounds tried a bespoke fixed-position banner (twice — it overlapped
    the page heading, then broke the login page); this replaces that
    entirely by reusing Filament's own "Saved"-toast mechanism instead.

    The markup here mirrors vendor/filament/notifications/resources/views/
    {notification,components/notification,components/{title,body,icon,
    close-button}}.blade.php closely (same classes, same
    x-data="notificationComponent(...)" Alpine component that already
    powers every real Filament notification), skipping only the outermost
    <x-filament-notifications::notification> wrapper — that one wraps
    itself around `$this->getId()` (the enclosing Livewire component's own
    ID, for its wire:key), which doesn't exist here since this isn't a
    Livewire component; a plain div with the same classes stands in for
    it. Everything else — icon, title, body, close-button — reuses
    Filament's real Blade components directly, so the visual style tracks
    Filament's own regardless of theme/branding changes, rather than us
    hand-duplicating Tailwind classes that could drift out of sync.

    Critical reason this exists at all: Notification::make()->send() (and
    even its pure-JS equivalent, `new FilamentNotification().send()` — see
    vendor/filament/notifications/resources/js/Notification.js) both end
    up, one way or another, calling into the `Notifications` Livewire
    component (vendor/filament/notifications/src/Livewire/Notifications.php)
    to push into its `$notifications` collection — and reaching a Livewire
    component's PHP always requires an actual request to
    /livewire/update (see vendor/livewire/livewire/js/features/
    supportListeners.js's `component.$wire.call('__dispatch', ...)`).
    Confirmed live in a real browser: with the network context genuinely
    severed, calling `new FilamentNotification().send()` triggers exactly
    that request, which fails with ERR_INTERNET_DISCONNECTED, and the
    toast never appears — not even after reconnecting. That's fatal for
    an *offline* indicator (the one moment it must work is exactly the
    moment that path is broken) and for the network-drop-mid-save case
    specifically (the other failure modes below — a real 4xx/5xx response,
    or 419 — did complete a round trip, so triggering a notification for
    those is comparatively safe, but network-drop is not).

    The templates below sidestep that entirely: they're inert HTML (never
    reaches the Notifications Livewire component, never makes a request)
    until cloned and manually Alpine-bound
    (`window.Alpine.initTree(clonedEl)`) straight into Filament's own
    notification container (`.fi-no` — the same fixed, already-reliable
    top-right stack every real toast renders into, confirmed via
    vendor/filament/notifications/resources/views/notifications.blade.php
    — so there's no new positioning code to get wrong, and no dependency
    on any particular page's layout/scroll position, unlike the two
    render-hook-injected custom banners from the previous approach).
--}}
<template id="fi-reliability-offline-template">
    <div
        x-data="notificationComponent({ notification: { id: 'fi-reliability-offline', duration: 'persistent' } })"
        style="{{ $warningStyle }}"
        class="fi-no-notification pointer-events-auto invisible w-full max-w-sm rounded-xl bg-white shadow-lg ring-1 fi-color-custom ring-custom-600/20 dark:bg-gray-900 dark:ring-custom-400/30 fi-status-warning fi-color-warning"
    >
        <div class="flex w-full gap-3 bg-custom-50 p-4 dark:bg-custom-400/10">
            <x-filament-notifications::icon color="warning" icon="heroicon-o-signal-slash" />

            <div class="mt-0.5 grid flex-1">
                <x-filament-notifications::title>
                    You&rsquo;re offline
                </x-filament-notifications::title>

                <x-filament-notifications::body class="mt-1">
                    Changes won&rsquo;t save until connection is restored.
                </x-filament-notifications::body>
            </div>

            <x-filament-notifications::close-button />
        </div>
    </div>
</template>

<template id="fi-reliability-save-failure-template">
    <div
        x-data="notificationComponent({ notification: { id: 'fi-reliability-save-failure', duration: 'persistent' } })"
        style="{{ $dangerStyle }}"
        class="fi-no-notification pointer-events-auto invisible w-full max-w-sm rounded-xl bg-white shadow-lg ring-1 fi-color-custom ring-custom-600/20 dark:bg-gray-900 dark:ring-custom-400/30 fi-status-danger fi-color-danger"
    >
        <div class="flex w-full gap-3 bg-custom-50 p-4 dark:bg-custom-400/10">
            <x-filament-notifications::icon color="danger" icon="heroicon-o-exclamation-triangle" />

            <div class="mt-0.5 grid flex-1">
                <x-filament-notifications::title>
                    Save failed
                </x-filament-notifications::title>

                <x-filament-notifications::body id="fi-reliability-save-failure-message" class="mt-1">
                    Please try again.
                </x-filament-notifications::body>

                {{--
                    Not <x-filament-notifications::actions> — that
                    component requires an $actions prop of pre-built
                    action view objects (it foreach's over $actions, it
                    doesn't accept a plain slot), which we don't have
                    here. This div copies its two classes
                    (fi-no-notification-actions flex gap-x-3) so the
                    layout still matches.
                --}}
                <div class="fi-no-notification-actions mt-3 flex gap-x-3">
                    <x-filament::button id="fi-reliability-save-failure-retry" size="sm" color="danger">
                        Retry
                    </x-filament::button>
                </div>
            </div>

            <x-filament-notifications::close-button />
        </div>
    </div>
</template>

<script>
    // Clones one of the <template>s above, Alpine-binds it (giving it
    // Filament's own real show/hide animation + auto-dismiss-unless-
    // persistent behavior, from notificationComponent in
    // vendor/filament/notifications/resources/js/components/notification.js),
    // and drops it straight into Filament's own notification stack. Any
    // existing notification with the same id is removed first, so a
    // second failure/offline event refreshes it in place rather than
    // stacking duplicates.
    // Maps each <template>'s id to the notification id baked into its own
    // x-data="notificationComponent({ notification: { id: '...' } })" —
    // kept here (rather than parsed back out of the template's markup) so
    // repeat calls can find and replace the previous instance by a plain
    // document.getElementById(id), and so closeReliabilityNotification()
    // below has a stable id to look up.
    var reliabilityNotificationIds = {
        'fi-reliability-offline-template': 'fi-reliability-offline',
        'fi-reliability-save-failure-template': 'fi-reliability-save-failure',
    };

    function showReliabilityNotification(templateId) {
        var container = document.querySelector('.fi-no');
        var template = document.getElementById(templateId);
        var id = reliabilityNotificationIds[templateId];

        if (! container || ! template || ! id) return null;

        document.getElementById(id)?.remove();

        var el = template.content.firstElementChild.cloneNode(true);
        el.id = id;
        container.appendChild(el);
        window.Alpine.initTree(el);

        return el;
    }

    function closeReliabilityNotification(id) {
        var el = document.getElementById(id);

        if (! el) return;

        window.Alpine.$data(el)?.close();
    }

    window.addEventListener('offline', () => {
        showReliabilityNotification('fi-reliability-offline-template');
    });

    window.addEventListener('online', () => {
        closeReliabilityNotification('fi-reliability-offline');
    });

    document.addEventListener('livewire:init', () => {
        const isDebugMode = @js((bool) config('app.debug'));

        let lastFailedCommit = null;

        const showSaveFailureNotification = (message) => {
            const el = showReliabilityNotification('fi-reliability-save-failure-template');

            if (! el) return;

            const messageEl = el.querySelector('#fi-reliability-save-failure-message') ?? el.querySelector('.fi-no-notification-body');

            if (messageEl) messageEl.textContent = message;

            el.querySelector('#fi-reliability-save-failure-retry')?.addEventListener('click', () => {
                closeReliabilityNotification('fi-reliability-save-failure');

                if (! lastFailedCommit) return;

                const { component, calls } = lastFailedCommit;

                if (calls.length > 0) {
                    calls.forEach(({ method, params }) => component.$wire.$call(method, ...params));
                } else {
                    component.$wire.$commit();
                }
            });
        };

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
                    showSaveFailureNotification('Please check your connection and try again.');

                    return;
                }

                if (status === 419) {
                    return;
                }

                if (! isDebugMode) {
                    preventDefault();
                    showSaveFailureNotification('Please try again.');
                }
            });
        });
    });
</script>
