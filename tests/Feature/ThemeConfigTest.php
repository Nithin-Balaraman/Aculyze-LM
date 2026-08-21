<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Tests\TestCase;

/**
 * Phase 1 visual theme overhaul — core colors, sidebar, buttons, cards.
 *
 * Like the collapsible sidebar and reliability notifications before it,
 * the actual visual result (gradients, glows, shadows, hover-lift) is
 * CSS/client-side and can't be meaningfully exercised by a PHP test
 * harness. This file only guards the plain PHP/config facts: the right
 * colors are registered with the right values in
 * App\Providers\Filament\AdminPanelProvider, and the CSS in
 * resources/css/filament/admin/theme.css targets Filament's own semantic
 * component classes (.fi-sidebar, .fi-sidebar-item, .fi-btn.fi-color-
 * primary, .fi-section) rather than shared Tailwind utility classes —
 * the latter would risk bleeding into the reliability toast notifications
 * (resources/views/filament/scripts/reliability-notifications.blade.php),
 * which happen to share several of the same utility classes (rounded-xl,
 * bg-white, shadow-sm, ...) as `.fi-section` cards.
 *
 * Verified live in a real headless-Chromium session, both light and dark
 * mode, at both the expanded (16rem) and collapsed (4.5rem) sidebar
 * widths: the navy gradient sidebar renders identically regardless of
 * the light/dark toggle (confirmed .fi-sidebar's computed background is
 * the same gradient in both modes — the point of overriding Filament's
 * own `lg:bg-transparent`/`dark:bg-gray-900` defaults), the active nav
 * item shows the cyan glow, primary buttons show the soft shadow, and
 * `.fi-section` cards show the hover-lift. Directly confirmed the
 * reliability toast notifications are unaffected: the offline
 * notification's computed style doesn't match `.fi-section`, and its
 * `transform` stays `none` on hover (no hover-lift bleeding in), with its
 * own pre-existing shadow/ring untouched.
 *
 * Still worth a manual check from a real user: overall visual "does this
 * feel right" polish, and the dashboard/topbar area in dark mode (out of
 * this phase's scope — only sidebar/buttons/cards/badges were touched)
 * still uses Filament's own default dark surface color, which doesn't
 * blend with the new navy sidebar quite as seamlessly as the reference
 * mockups — likely Phase 2 material, not addressed here.
 */
class ThemeConfigTest extends TestCase
{
    public function test_primary_and_info_are_unchanged_from_before_this_phase(): void
    {
        $colors = Filament::getPanel('admin')->getColors();

        $this->assertSame(Color::hex('#4174B9'), $colors['primary']);
        $this->assertSame(Color::hex('#2DC4ED'), $colors['info']);
    }

    /**
     * `navy` was previously #0E1131 (unused anywhere except this
     * registration) — updated to the exact brand "Deep navy" now that
     * it's actually used, as the base of the sidebar gradient.
     */
    public function test_navy_is_updated_to_the_exact_brand_hex(): void
    {
        $colors = Filament::getPanel('admin')->getColors();

        $this->assertSame(Color::hex('#0F1B3D'), $colors['navy']);
    }

    /**
     * The new dedicated cyan brand-accent color, kept separate from
     * `info` (a different, near-identical cyan already used for
     * informational badges) so retuning one can't accidentally affect
     * the other.
     */
    public function test_a_dedicated_accent_color_is_registered_for_the_sidebar_glow(): void
    {
        $colors = Filament::getPanel('admin')->getColors();

        $this->assertArrayHasKey('accent', $colors);
        $this->assertSame(Color::hex('#29C5E6'), $colors['accent']);
        $this->assertNotSame($colors['accent'], $colors['info']);
    }

    /**
     * danger/success/warning are deliberately left unregistered by this
     * panel, so Filament falls back to its own stock Red/Green/Amber —
     * confirms this phase didn't touch them, per its explicit scope.
     */
    public function test_danger_success_and_warning_are_left_as_filaments_stock_colors(): void
    {
        $colors = Filament::getPanel('admin')->getColors();

        $this->assertArrayNotHasKey('danger', $colors);
        $this->assertArrayNotHasKey('success', $colors);
        $this->assertArrayNotHasKey('warning', $colors);
    }

    /**
     * The existing lead-temperature accents (coral/gold/slateblue) are
     * untouched by this phase.
     */
    public function test_the_existing_lead_temperature_colors_are_unchanged(): void
    {
        $colors = Filament::getPanel('admin')->getColors();

        $this->assertSame(Color::hex('#F0653C'), $colors['coral']);
        $this->assertSame(Color::hex('#C99A3D'), $colors['gold']);
        $this->assertSame(Color::hex('#5B7C99'), $colors['slateblue']);
    }

    public function test_theme_css_targets_filaments_own_sidebar_classes_not_utility_classes(): void
    {
        $css = $this->themeCss();

        $this->assertStringContainsString('.fi-sidebar {', $css);
        $this->assertStringContainsString('.fi-sidebar-header {', $css);
        $this->assertStringContainsString('.fi-sidebar-item.fi-active .fi-sidebar-item-button', $css);
    }

    public function test_theme_css_targets_primary_buttons_specifically(): void
    {
        $css = $this->themeCss();

        $this->assertStringContainsString('.fi-btn.fi-color-primary', $css);
    }

    /**
     * Regression guard for the "don't bleed into the reliability toasts"
     * requirement: the card hover-lift must be scoped to `.fi-section`
     * (Filament's real card component class), never to the bare utility
     * classes the toast notifications happen to also use.
     */
    public function test_theme_css_scopes_card_hover_lift_to_the_semantic_section_class(): void
    {
        $css = $this->themeCss();

        $this->assertStringContainsString('.fi-section {', $css);
        $this->assertStringContainsString('.fi-section:hover {', $css);
        $this->assertStringNotContainsString('.rounded-xl.bg-white', $css);
        $this->assertStringNotContainsString('.fi-no-notification', $css);
    }

    private function themeCss(): string
    {
        return file_get_contents(resource_path('css/filament/admin/theme.css'));
    }
}
