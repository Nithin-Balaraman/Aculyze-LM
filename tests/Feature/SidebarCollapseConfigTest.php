<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Tests\TestCase;

/**
 * Collapsible sidebar: entirely Filament's own built-in feature
 * (App\Providers\Filament\AdminPanelProvider::panel()'s
 * ->sidebarCollapsibleOnDesktop() call), not custom-built — so there's no
 * new PHP logic to unit-test. The actual collapse/expand interaction and
 * its persistence are client-side Alpine.js (Alpine's $persist plugin,
 * browser localStorage — see the panel config's own comment), which a
 * PHP test harness can't meaningfully exercise, mirroring the same
 * "config vs. client-side behavior" split already noted elsewhere in this
 * app's own test suite (e.g. the Prospect View page's filter reactivity).
 * This just guards the one thing that IS a plain PHP assertion: the panel
 * is actually configured the way this feature depends on.
 */
class SidebarCollapseConfigTest extends TestCase
{
    public function test_the_admin_panel_has_the_sidebar_collapsible_on_desktop(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertTrue($panel->isSidebarCollapsibleOnDesktop());
    }

    /**
     * Not fully collapsible (i.e. hidden to zero width) — the app wants a
     * persistent icon rail when collapsed, not the sidebar disappearing
     * entirely.
     */
    public function test_the_admin_panel_is_not_fully_collapsible(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertFalse($panel->isSidebarFullyCollapsibleOnDesktop());
    }

    /**
     * Confirms the pre-existing "Left panel UI fix" width customization
     * (Phase 2) is untouched by this change — sidebarWidth() is the
     * expanded width, entirely independent of the collapsed-rail setting.
     */
    public function test_the_existing_sidebar_width_customization_is_unchanged(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertSame('16rem', $panel->getSidebarWidth());
    }

    /**
     * Checked visually (see the investigation for this feature): the
     * default 4.5rem collapsed-rail width already looks correct against
     * this app's branding and nav icons, so it's deliberately left at
     * Filament's own default rather than overridden.
     */
    public function test_the_collapsed_sidebar_width_is_left_at_filaments_default(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertSame('4.5rem', $panel->getCollapsedSidebarWidth());
    }
}
