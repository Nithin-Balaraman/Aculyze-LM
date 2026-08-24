<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Tests\TestCase;

/**
 * `resources/css/filament/admin/theme.css` and
 * `app/Providers/Filament/AdminPanelProvider.php` were reverted to their
 * pre-theming state (commit 434311c) after the full theme overhaul (the
 * "second attempt", commits 2ef30f1 through bbfce0b) proved impossible to
 * verify live on Hostinger due to a server-level LiteSpeed cache serving
 * stale pages after deploy. This revert commit formalizes, in git, a
 * manual local revert of those two files that was already deployed to
 * production. Every test below that asserted content exclusive to that
 * full theming pass has been removed; only tests for values/files the
 * revert leaves untouched remain — the pre-theme color registrations,
 * and the separate KPI tile/sparkline/chart-modal Blade files and the
 * avatar provider, none of which were part of the two-file revert.
 */
class ThemeConfigTest extends TestCase
{
    public function test_primary_and_info_are_unchanged(): void
    {
        $colors = Filament::getPanel('admin')->getColors();

        $this->assertSame(Color::hex('#4174B9'), $colors['primary']);
        $this->assertSame(Color::hex('#2DC4ED'), $colors['info']);
    }

    public function test_navy_is_the_pre_theme_brand_hex(): void
    {
        $colors = Filament::getPanel('admin')->getColors();

        $this->assertSame(Color::hex('#0E1131'), $colors['navy']);
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
}
