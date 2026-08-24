<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Tests\TestCase;

/**
 * Full visual theme overhaul — styling/interaction polish only (colors,
 * depth, shadows, glow, transitions, hover states); no content, layout,
 * or data changes anywhere. Planned from a reference mockup + screen
 * recording (reviewed frame-by-frame via ffmpeg, since video can't be
 * played directly) and grounded in an investigation of this app's actual
 * Filament component classes — see the investigation this was built from
 * for the full component inventory and reasoning.
 *
 * This is the second attempt at this overhaul. The first ("Phase 1",
 * commit f910ed2) was fully reverted (434311c) at the user's request in
 * favor of one complete pass instead of narrow phases — this rewrite
 * covers substantially more ground: sidebar hover vs. active states are
 * now deliberately distinct (Phase 1 only had one glow state), buttons
 * get hover AND active/press states (Phase 1 was static), and this pass
 * additionally covers badges, table rows, the topbar, and individual KPI
 * tile cards, none of which Phase 1 touched.
 *
 * Two deliberate, explicitly-approved non-CSS touch-points:
 * - `fi-kpi-tile`/`fi-kpi-tile-icon` stable hook classes added to
 *   resources/views/filament/widgets/kpi-{tile,band}.blade.php — the KPI
 *   tiles had no distinguishing class before (just the bare
 *   `rounded-lg border border-gray-200` utility combo, reused verbatim
 *   elsewhere in the app), so styling them individually without these
 *   would have meant risking a utility-class selector reaching into
 *   unrelated elements.
 * - App\Filament\AvatarProviders\InitialsAvatarProvider's hardcoded SVG
 *   fill colors updated to the new brand hex — CSS can't reach inside a
 *   generated data: URI.
 *
 * The actual visual/interaction result is CSS/client-side and can't be
 * meaningfully exercised by a PHP test harness — this file only guards
 * the plain PHP/config facts: the right colors are registered, the CSS
 * targets the intended semantic classes (never the reliability toast's
 * shared utility classes), and the two approved non-CSS touch-points
 * have their exact new values.
 *
 * Verified live in a real headless-Chromium session, both light and dark
 * mode, at both the expanded and collapsed sidebar widths: hovering a
 * non-active nav item shows only a plain tint (no glow/bar), while the
 * actual active item keeps its left accent bar + glow regardless of
 * where the cursor is; the sidebar renders identically navy in both
 * theme modes; KPI tile icons show the tinted badge background
 * (confirmed via computed style: rgba(41, 197, 230, 0.15) background,
 * rgb(105, 214, 238) icon color in dark mode); primary buttons visibly
 * brighten on hover; table rows show the accent-tinted hover; and the
 * reliability offline notification was directly confirmed unaffected —
 * its computed style doesn't match `.fi-section` or `.fi-kpi-tile`, and
 * its `transform` stays `none` on hover.
 */
class ThemeConfigTest extends TestCase
{
    public function test_primary_and_info_are_unchanged(): void
    {
        $colors = Filament::getPanel('admin')->getColors();

        $this->assertSame(Color::hex('#4174B9'), $colors['primary']);
        $this->assertSame(Color::hex('#2DC4ED'), $colors['info']);
    }

    public function test_navy_is_the_exact_brand_hex(): void
    {
        $colors = Filament::getPanel('admin')->getColors();

        $this->assertSame(Color::hex('#0F1B3D'), $colors['navy']);
    }

    /**
     * The dedicated cyan brand-accent color, kept separate from `info` (a
     * different, near-identical cyan already used for informational
     * badges) so retuning one can't accidentally affect the other.
     */
    public function test_a_dedicated_accent_color_is_registered(): void
    {
        $colors = Filament::getPanel('admin')->getColors();

        $this->assertArrayHasKey('accent', $colors);
        $this->assertSame(Color::hex('#29C5E6'), $colors['accent']);
        $this->assertNotSame($colors['accent'], $colors['info']);
    }

    /**
     * danger/success/warning are deliberately left unregistered by this
     * panel, so Filament falls back to its own stock Red/Green/Amber —
     * only their pill *depth* is restyled (theme.css's `.fi-badge` rule),
     * never their hue.
     */
    public function test_danger_success_and_warning_are_left_as_filaments_stock_colors(): void
    {
        $colors = Filament::getPanel('admin')->getColors();

        $this->assertArrayNotHasKey('danger', $colors);
        $this->assertArrayNotHasKey('success', $colors);
        $this->assertArrayNotHasKey('warning', $colors);
    }

    public function test_the_existing_lead_temperature_colors_are_unchanged(): void
    {
        $colors = Filament::getPanel('admin')->getColors();

        $this->assertSame(Color::hex('#F0653C'), $colors['coral']);
        $this->assertSame(Color::hex('#C99A3D'), $colors['gold']);
        $this->assertSame(Color::hex('#5B7C99'), $colors['slateblue']);
    }

    /**
     * The topbar/user-menu avatar's colors are generated as an inline SVG
     * data URI (not CSS) — approved touch-point #2.
     */
    public function test_the_avatar_provider_uses_the_new_brand_hex_values(): void
    {
        $source = file_get_contents(app_path('Filament/AvatarProviders/InitialsAvatarProvider.php'));

        $this->assertStringContainsString('#0F1B3D', $source);
        $this->assertStringContainsString('#29C5E6', $source);
        $this->assertStringNotContainsString('#0E1131', $source);
        $this->assertStringNotContainsString('#2DC4ED', $source);
    }

    /**
     * Approved touch-point #1: the two KPI tile Blade files carry the
     * stable hook classes theme.css targets, instead of only the bare
     * utility-class combo they had before.
     */
    public function test_kpi_tile_blade_files_carry_the_stable_hook_classes(): void
    {
        $tile = file_get_contents(resource_path('views/filament/widgets/kpi-tile.blade.php'));
        $band = file_get_contents(resource_path('views/filament/widgets/kpi-band.blade.php'));

        $this->assertStringContainsString('fi-kpi-tile', $tile);
        $this->assertStringContainsString('fi-kpi-tile-icon', $tile);
        $this->assertStringContainsString('fi-kpi-tile', $band);
        $this->assertStringContainsString('fi-kpi-tile-icon', $band);
    }

    public function test_theme_css_targets_the_sidebar_and_distinguishes_hover_from_active(): void
    {
        $css = $this->themeCss();

        $this->assertStringContainsString('.fi-sidebar {', $css);
        $this->assertStringContainsString('.fi-sidebar-header {', $css);

        // Hover (not active) and active must be genuinely separate rules —
        // Phase 1's mistake was conflating them into one glow-on-hover
        // rule; this regression guard fails if that happens again.
        $this->assertStringContainsString('.fi-sidebar-item:not(.fi-active) .fi-sidebar-item-button:hover', $css);
        $this->assertStringContainsString('.fi-sidebar-item.fi-active .fi-sidebar-item-button', $css);
        $this->assertStringContainsString('.fi-sidebar-item.fi-active {', $css);
    }

    public function test_theme_css_gives_primary_buttons_hover_and_active_states(): void
    {
        $css = $this->themeCss();

        $this->assertStringContainsString('.fi-btn.fi-color-primary:not(.fi-btn-outlined) {', $css);
        $this->assertStringContainsString('.fi-btn.fi-color-primary:not(.fi-btn-outlined):hover', $css);
        $this->assertStringContainsString('.fi-btn.fi-color-primary:not(.fi-btn-outlined):active', $css);
    }

    public function test_theme_css_targets_badges_table_rows_and_topbar(): void
    {
        $css = $this->themeCss();

        $this->assertStringContainsString('.fi-badge {', $css);
        $this->assertStringContainsString('.fi-ta-row', $css);
        $this->assertStringContainsString('.fi-topbar nav', $css);
    }

    public function test_theme_css_gives_kpi_tiles_their_own_card_and_icon_treatment(): void
    {
        $css = $this->themeCss();

        $this->assertStringContainsString('.fi-kpi-tile {', $css);
        $this->assertStringContainsString('.fi-kpi-tile:hover', $css);
        $this->assertStringContainsString('.fi-kpi-tile-icon {', $css);
    }

    /**
     * Regression guard for the "don't bleed into the reliability toasts"
     * requirement: none of this file's card/tile rules may be scoped to
     * the toast notification's own class or the bare utility-class combo
     * it shares with `.fi-section`.
     */
    public function test_theme_css_never_targets_the_reliability_toast_classes(): void
    {
        $css = $this->themeCss();

        $this->assertStringNotContainsString('.fi-no-notification', $css);
        $this->assertStringNotContainsString('.rounded-xl.bg-white', $css);
    }

    /**
     * Bug fix: the sidebar header's `background: transparent` override
     * only won in light mode. Tailwind's `dark:bg-gray-900` compiles to
     * `.dark .dark\:bg-gray-900` (two classes), which outranks a bare
     * `.fi-sidebar-header` (one class) regardless of stylesheet order —
     * confirmed live, this left a visible gray seam across the header in
     * dark mode, exactly where the sidebar collapse-toggle chevron sits,
     * reading as the chevron being clipped/cut off at a boundary.
     * `:is(.dark) .fi-sidebar-header` matches that specificity, the same
     * pattern already used below for the topbar.
     */
    public function test_theme_css_keeps_the_sidebar_header_transparent_in_dark_mode(): void
    {
        $css = $this->themeCss();

        $this->assertStringContainsString(':is(.dark) .fi-sidebar-header', $css);
    }

    /**
     * Bug fix: `.fi-section` (dashboard widgets, form sections) and
     * `.fi-ta-ctn` (a resource List page's table wrapper) are two
     * *separate* Filament classes — boosting only `.fi-section`'s shadow
     * left every resource List page (e.g. Call Records) on Filament's
     * stock `shadow-sm`, looking flat next to the dashboard. Confirmed
     * live: `.fi-ta-ctn`'s computed box-shadow matched the plain
     * Filament default until this fix.
     */
    public function test_theme_css_gives_the_table_container_the_same_card_depth_as_sections(): void
    {
        $css = $this->themeCss();

        $this->assertStringContainsString('.fi-ta-ctn', $css);
    }

    /**
     * Bug fix: the sidebar got no border/shadow of its own at desktop
     * widths (Filament resets its shadow-xl/ring classes to none via
     * `lg:shadow-none`/`lg:ring-0`, meant for the old design where the
     * sidebar shared the topbar's plain background). With the sidebar
     * now permanently navy against a topbar/content area that varies by
     * theme, that missing edge read as the navy visibly "bleeding" past
     * its own boundary into the topbar, most noticeably right where the
     * collapse-toggle chevron sits — confirmed live in both collapsed
     * and expanded states, both themes. A box-shadow (not a border,
     * which would shift layout by 1px) draws a permanent hairline along
     * the sidebar's true right edge.
     */
    public function test_theme_css_gives_the_sidebar_a_crisp_permanent_edge(): void
    {
        $css = $this->themeCss();

        $this->assertStringContainsString('.fi-sidebar {', $css);
        $this->assertMatchesRegularExpression('/\.fi-sidebar\s*\{[^}]*box-shadow:\s*1px 0 0 0/s', $css);
    }

    /**
     * Bug fix: light mode's `.fi-body` background was Filament's stock
     * `bg-gray-50` — near-white, reading as stark next to the rest of
     * this theme's depth work, and leaving bare, texture-less empty space
     * above a resource List page's own filter tabs. Applies to `.fi-body`
     * itself (the one root element every panel page renders inside), so
     * it reaches dashboards, resource list pages, and forms alike with a
     * single rule rather than a per-page change. A repeating radial-dot
     * pattern layered under the existing brand-tinted gradient gives
     * those empty areas a deliberate subtle texture instead of a blank gap.
     */
    public function test_theme_css_gives_the_page_background_a_subtle_brand_tint_and_texture(): void
    {
        $css = $this->themeCss();

        $this->assertMatchesRegularExpression('/\.fi-body\s*\{[^}]*background-image:\s*\n\s*radial-gradient/s', $css);
        $this->assertMatchesRegularExpression('/\.fi-body\s*\{[^}]*linear-gradient/s', $css);
    }

    /**
     * Bug fix: the dark-mode topbar has gone through three attempts now —
     * matched *exactly* to the sidebar's own top gradient stop (fixed a
     * seam, but read as one undifferentiated dark slab with the
     * sidebar); a separate, deliberately-lighter shade (fixed the slab
     * problem, but read as an odd washed-out gray next to the rest of
     * the palette); matched exactly to the new deep page background
     * (closest to the reference mockup, but read as a bit too dark/flat
     * on its own). `--dm-topbar` is a small, deliberate step up from
     * `--dm-bg` — same navy hue, not a new tone, unlike the earlier
     * "washed-out" attempt's much bigger jump.
     */
    public function test_theme_css_gives_the_dark_mode_topbar_a_gentle_lighten_from_the_page_background(): void
    {
        $css = $this->themeCss();

        $this->assertStringContainsString('--dm-bg:', $css);
        $this->assertStringContainsString('--dm-topbar:', $css);
        $this->assertMatchesRegularExpression('/:is\(\.dark\)\s*\.fi-topbar nav\s*\{[^}]*background-color:\s*var\(--dm-topbar\);/s', $css);
        $this->assertStringNotContainsString('--topbar-navy-dark', $css);
    }

    /**
     * Bug fix: a previous attempt reverted `.fi-sidebar-nav` (the
     * scrollable nav-item list, below the header) to
     * `scrollbar-gutter: auto`, so it would only lose width to a real
     * scrollbar at the moment one is actually needed, rather than
     * permanently — but confirmed live, this app's nav list genuinely
     * does overflow at a shorter viewport, and the instant it does, the
     * exact same header/nav width mismatch reappears (only the nav
     * shrinks to make room). The robust fix is the opposite: give
     * `.fi-sidebar-header` the *same permanent* reservation Filament's
     * nav already has (`scrollbar-gutter: stable`, paired with
     * `overflow-y: hidden` so no scrollbar is ever actually drawn there)
     * — with both elements always reserving the identical width, they
     * can never mismatch again, in any scenario.
     */
    public function test_theme_css_gives_the_sidebar_header_the_same_permanent_scrollbar_gutter_as_the_nav(): void
    {
        $css = $this->themeCss();

        $this->assertMatchesRegularExpression('/\.fi-sidebar-header\s*\{[^}]*overflow-y:\s*hidden;\s*\n\s*scrollbar-gutter:\s*stable;/s', $css);
    }

    /**
     * Bug fix: the page background's subtle dot texture (added for List
     * pages' bare space above their filter tabs) only applied in light
     * mode — dark mode kept the earlier plain radial glow with no dot
     * texture at all. A dark-mode dot needs a different color from
     * light mode's (a dark navy dot is invisible against this near-black
     * background), so a pale cyan-tinted dot is used instead.
     */
    public function test_theme_css_gives_the_dark_mode_page_background_the_same_dot_texture(): void
    {
        $css = $this->themeCss();

        $this->assertMatchesRegularExpression('/:is\(\.dark\)\s*\.fi-body\s*\{[^}]*background-image:\s*\n\s*radial-gradient\(rgba\(148, 197, 230,/s', $css);
    }

    /**
     * Bug fix: dark mode's primary buttons and search-bar focus ring
     * rendered in the same steel-blue `primary` color as light mode,
     * which read as a flat, washed-out gray-blue against the new deep
     * navy-black page background. The reference mockup uses the vivid
     * cyan accent for both — already the color used for the sidebar's
     * active-item glow and the KPI icon badges, so this keeps dark
     * mode's accent language consistent throughout.
     */
    public function test_theme_css_gives_dark_mode_buttons_and_search_focus_the_cyan_accent(): void
    {
        $css = $this->themeCss();

        $this->assertMatchesRegularExpression('/:is\(\.dark\)\s*\.fi-btn\.fi-color-primary:not\(\.fi-btn-outlined\)\s*\{[^}]*background-color:\s*rgb\(var\(--accent-500-rgb\)\);/s', $css);
        $this->assertStringContainsString('.fi-global-search .fi-input-wrp:focus-within', $css);
        $this->assertStringContainsString('--tw-ring-color: rgb(var(--accent-500-rgb)) !important;', $css);
    }

    /**
     * Bug fix: dark mode's KPI icon badge was a solid, inverted cyan
     * fill — the reference mockup instead uses a soft, translucent cyan
     * "glass" tint (the dark surface still shows through) with a bright
     * cyan icon on top.
     */
    public function test_theme_css_gives_dark_mode_kpi_icon_badges_a_translucent_tint(): void
    {
        $css = $this->themeCss();

        $this->assertMatchesRegularExpression('/:is\(\.dark\)\s*\.fi-kpi-tile-icon\s*\{[^}]*background:\s*rgba\(var\(--accent-500-rgb\),\s*0\.16\);/s', $css);
    }

    /**
     * Bug fix: dark-mode card/table surfaces relied on Filament's own
     * `dark:bg-gray-900`, which sits too close to the page's own
     * `dark:bg-gray-950` for the shadow/rim depth work to actually read
     * as "raised" — confirmed live, the two were hard to tell apart at a
     * glance. `--dark-surface`/`--dark-surface-raised` are explicit,
     * distinctly lighter, navy-tinted surface tones.
     */
    public function test_theme_css_gives_dark_mode_cards_a_distinctly_lighter_surface(): void
    {
        $css = $this->themeCss();

        $this->assertStringContainsString('--dark-surface:', $css);
        $this->assertStringContainsString('--dark-surface-raised:', $css);
        $this->assertStringContainsString('background-color: var(--dark-surface);', $css);
        $this->assertStringContainsString('background-color: var(--dark-surface-raised);', $css);
    }

    /**
     * Bug fix: the KPI sparkline (resources/views/filament/widgets/
     * kpi-sparkline.blade.php) already set `fill: true` and
     * `elements.point.radius: 0`, but rendered live as a thin line with
     * visible dot markers — Filament's own chart Alpine component
     * (vendor/filament/widgets/resources/js/components/chart.js) does
     * `options.pointRadius ??= 2` unconditionally, which only backs off
     * when that exact top-level key is already present; the nested
     * `elements.point.radius` alone didn't stop it. Also bumped the fill
     * opacity from a barely-visible 0.08 to a genuinely-visible 0.3, so
     * it reads as a filled area chart rather than a line with a faint
     * tint underneath.
     */
    public function test_kpi_sparkline_is_a_filled_area_chart_with_no_point_markers(): void
    {
        $blade = file_get_contents(resource_path('views/filament/widgets/kpi-sparkline.blade.php'));

        $this->assertStringContainsString("'pointRadius' => 0,", $blade);
        $this->assertStringContainsString("'fill' => true,", $blade);
        $this->assertStringNotContainsString('rgba(65, 116, 185, 0.08)', $blade);
    }

    /**
     * Bug fix: with no layout padding, Chart.js's auto-computed y-axis
     * max lands exactly on the data's peak value, drawing the curve
     * right up to the canvas's own top edge — the line's own borderWidth
     * then has nowhere to go but off the top of the canvas, clipping the
     * peak (confirmed live).
     */
    public function test_kpi_sparkline_has_top_padding_so_the_peak_is_not_clipped(): void
    {
        $blade = file_get_contents(resource_path('views/filament/widgets/kpi-sparkline.blade.php'));

        $this->assertStringContainsString("'layout' => ['padding' => ['top' => 4]],", $blade);
    }

    /**
     * Follow-up request: overlay/floating UI (dropdown panels, modals,
     * tabs) still used Filament's stock plain `bg-white dark:bg-gray-900`
     * — unstyled next to every other surface in the app.
     * `.fi-dropdown-panel` is one shared component covering four
     * separate-looking features: the account/user menu, a table row's
     * "⋮" action-group menu, the column-toggle popover, and the filter
     * popover — all four render through `<x-filament::dropdown>`.
     * `.fi-modal-window` is Filament's own Create/Edit/View/delete-
     * confirmation modal.
     */
    public function test_theme_css_gives_overlay_panels_the_same_dark_surface_as_cards(): void
    {
        $css = $this->themeCss();

        $this->assertMatchesRegularExpression('/\.fi-dropdown-panel,\s*\n\s*\.fi-modal-window,\s*\n\s*\.fi-chart-detail-modal\s*\{/s', $css);
        $this->assertMatchesRegularExpression('/:is\(\.dark\)\s*\.fi-dropdown-panel,\s*\n:is\(\.dark\)\s*\.fi-modal-window,\s*\n:is\(\.dark\)\s*\.fi-chart-detail-modal\s*\{[^}]*background-color:\s*var\(--dark-surface\);/s', $css);
    }

    /**
     * Bug fix: a modal's sticky header/footer bar paints its own opaque
     * background so content doesn't show through while the body scrolls
     * underneath — that background has to match the modal's own new
     * dark surface, or the sticky bar looks like a mismatched patch
     * floating over the modal.
     */
    public function test_theme_css_matches_modal_sticky_bars_to_the_modal_surface(): void
    {
        $css = $this->themeCss();

        $this->assertMatchesRegularExpression('/:is\(\.dark\)\s*\.fi-modal-header\.fi-sticky,\s*\n:is\(\.dark\)\s*\.fi-modal-footer\.fi-sticky,\s*\n:is\(\.dark\)\s*\.fi-chart-detail-modal-header\s*\{[^}]*background-color:\s*var\(--dark-surface\);/s', $css);
    }

    /**
     * Follow-up request: a resource List page's Pending/History-style
     * tab switcher (`.fi-tabs` without Filament's `.fi-contained`
     * modifier) still used the stock plain surface, and its active
     * tab's label/icon color came from `primary` (this app's steel-blue)
     * even in dark mode, where the rest of the accent language is the
     * cyan `accent` color.
     */
    public function test_theme_css_gives_dark_mode_tabs_the_dark_surface_and_cyan_active_state(): void
    {
        $css = $this->themeCss();

        $this->assertMatchesRegularExpression('/:is\(\.dark\)\s*\.fi-tabs:not\(\.fi-contained\)\s*\{[^}]*background-color:\s*var\(--dark-surface\);/s', $css);
        $this->assertMatchesRegularExpression('/:is\(\.dark\)\s*\.fi-tabs-item\.fi-active\s*\{[^}]*background-color:\s*rgba\(var\(--accent-500-rgb\),/s', $css);
        $this->assertStringContainsString('.fi-tabs-item.fi-active .fi-tabs-item-label,', $css);
    }

    /**
     * The chart-detail modal's header needed a stable hook class (it had
     * none beyond bare Tailwind utilities) to be individually targetable
     * for the dark-surface fix above — same "add a hook class" pattern
     * already used for the KPI tiles.
     */
    public function test_chart_detail_modal_header_carries_a_stable_hook_class(): void
    {
        $blade = file_get_contents(resource_path('views/filament/widgets/chart-detail-modal.blade.php'));

        $this->assertStringContainsString('fi-chart-detail-modal-header', $blade);
    }

    private function themeCss(): string
    {
        return file_get_contents(resource_path('css/filament/admin/theme.css'));
    }
}
