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

    private function themeCss(): string
    {
        return file_get_contents(resource_path('css/filament/admin/theme.css'));
    }
}
