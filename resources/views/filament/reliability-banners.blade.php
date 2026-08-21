{{--
    Markup for the two reliability banners (offline detection + save-
    failure feedback) — registered panel-wide via AdminPanelProvider on
    PanelsRenderHook::CONTENT_START, which renders inside <main>, right
    before the page's own content (see vendor/filament/filament/
    resources/views/components/layout/index.blade.php). Deliberately NOT
    position: fixed — an earlier version overlaid the page's own heading
    (e.g. "Follow Ups") instead of pushing it down, since a fixed overlay
    ignores document flow entirely. Rendered here, in normal flow, ahead
    of {{ $slot }} that actually holds the heading, a shown banner just
    adds height above it like any other block element — no overlap, no
    JS/padding workaround needed.

    The controlling logic (window.addEventListener('offline'|'online'),
    Livewire.hook('request'|'commit')) lives in a separate file —
    resources/views/filament/scripts/reliability-banners.blade.php,
    registered on PanelsRenderHook::SCRIPTS_AFTER — since a <script> tag's
    own position in the DOM doesn't matter, only running once per full
    page load does; CONTENT_START sits inside the page's own re-rendered
    content area, so a script placed there would re-run on every Livewire
    update, silently piling up duplicate listeners. Both files' element
    IDs (fi-offline-banner, fi-save-failure-banner, ...) are what tie them
    together — document.getElementById(...) doesn't care which file
    rendered the element it finds.
--}}
<div class="mb-6 flex flex-col gap-3">
    <div
        id="fi-offline-banner"
        style="display: none;"
        class="items-center justify-center gap-2 rounded-lg bg-red-600 px-4 py-3 text-center text-sm font-semibold text-white shadow-lg"
    >
        <x-filament::icon icon="heroicon-o-signal-slash" class="h-5 w-5 shrink-0" />
        You&rsquo;re offline &mdash; changes won&rsquo;t save until connection is restored.
    </div>

    <div
        id="fi-save-failure-banner"
        style="display: none;"
        class="items-center justify-center gap-3 rounded-lg bg-amber-600 px-4 py-3 text-center text-sm font-semibold text-white shadow-lg"
    >
        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 shrink-0" />
        <span id="fi-save-failure-banner-message">Save failed &mdash; please try again.</span>
        <button
            type="button"
            id="fi-save-failure-banner-retry"
            class="rounded-md bg-white/20 px-3 py-1 font-semibold transition hover:bg-white/30"
        >
            Retry
        </button>
        <button
            type="button"
            id="fi-save-failure-banner-dismiss"
            class="rounded-md bg-white/10 px-2 py-1 font-semibold transition hover:bg-white/20"
            aria-label="Dismiss"
        >
            &times;
        </button>
    </div>
</div>
