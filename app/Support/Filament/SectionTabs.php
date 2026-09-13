<?php

namespace App\Support\Filament;

use Filament\Infolists\Components\Tabs;

/**
 * A thin factory around Filament's own native `Infolists\Components\Tabs` —
 * NOT a new tab implementation. Every long, section-heavy read-only page
 * that adopts section navigation calls `SectionTabs::make()` instead of
 * repeating the same chained calls, so the house conventions below live in
 * exactly one place:
 *
 * - the active tab is persisted in the URL query string (`?section=...` by
 *   default), never only in-memory, so a link/refresh/bookmark reopens the
 *   same tab — see `persistTabInQueryString()`,
 * - a fixed `fi-section-tabs` hook class carries the sticky-nav-bar CSS in
 *   `resources/css/filament/admin/theme.css`, scoped to only this pattern
 *   (never a blanket `.fi-tabs` rule, matching this codebase's existing
 *   "hook class, not shared utility class" styling convention),
 * - hidden `Tab`s are stripped BEFORE Filament ever sees them (see below) —
 *   never left in the schema for Filament's own visibility filtering to
 *   remove later.
 *
 * ## Why hidden tabs are pre-filtered here rather than left to `->visible()`
 *
 * Confirmed directly against the installed Filament v3.3.54 source
 * (`Tabs::getActiveTab()` + `ComponentContainer::getComponents()`): when a
 * `Tab` earlier in the array is hidden, `getComponents()` correctly excludes
 * it from PHP's own visible-tab list but does NOT reindex the remaining
 * keys, while the Blade view's client-side `tabs` array IS reindexed
 * (`->filter(...)->values()`). `getActiveTab()`'s query-string match then
 * returns `$originalKey + 1`, which the client-side script reads as an
 * index into its OWN reindexed array — landing on the wrong tab whenever a
 * `?section=` link targets a tab that comes AFTER a hidden one. Reproduced
 * directly: an Employee viewing a not-yet-Released Version (so "PDF" is
 * hidden) opening `?section=...-release-tab` was silently placed on "Send
 * History" instead. Pre-filtering to only the tabs THIS viewer can see,
 * before Filament ever builds its container, means Filament's own
 * (already-correct) filtering is a no-op — there is no gap left to
 * mis-align, so the native id-matching, the sticky nav's URL-rewrite on
 * click, and the exact requested tab order are all preserved unchanged.
 */
class SectionTabs
{
    /**
     * @param  array<int, Tabs\Tab>  $tabs
     */
    public static function make(string $id, array $tabs, string $queryStringKey = 'section'): Tabs
    {
        $visibleTabs = array_values(array_filter(
            $tabs,
            fn (Tabs\Tab $tab): bool => $tab->isVisible(),
        ));

        return Tabs::make()
            ->id($id)
            ->tabs($visibleTabs)
            ->contained(false)
            ->persistTabInQueryString($queryStringKey)
            ->extraAttributes(['class' => 'fi-section-tabs'])
            // Visual fix: the containing Infolist's schema-level grid
            // defaults to 2 columns at the `lg` breakpoint (confirmed
            // directly — `.fi-fo-component-ctn` renders `grid-template-
            // columns: 548px 548px` on a 1120px-wide page) whenever the
            // page's own infolist() never calls ->columns() itself (see
            // ManageCommercialVersion/ViewCommercialVersion, both of which
            // pass only this one Tabs component as their infolist's whole
            // schema). Without this, the Tabs component — and everything
            // inside every one of its Tabs — silently occupies only the
            // first of those two columns, leaving the second entirely
            // empty: exactly the "half the laptop width, huge unused area
            // on the right" symptom. columnSpanFull() makes this pattern
            // correct regardless of how many columns its parent Infolist
            // happens to default to, on every page that adopts it.
            ->columnSpanFull();
    }
}
