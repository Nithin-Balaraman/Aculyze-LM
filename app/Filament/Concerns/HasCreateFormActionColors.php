<?php

namespace App\Filament\Concerns;

use Filament\Actions\Action;

/**
 * Colors a CreateRecord page's three footer actions so they read as three
 * distinct decisions at a glance, rather than Filament's own defaults
 * (Create: unset -> falls through to plain brand `primary`; Create
 * Another/Cancel: both plain `gray`, indistinguishable from each other).
 *
 * Same reusable-constant shape as ProspectResource::BACK_BUTTON_COLOR — any
 * CreateRecord page adds this in one line (`use HasCreateFormActionColors;`)
 * rather than re-deriving/duplicating the choice.
 *
 * Color choices, checked against every color already registered in
 * AdminPanelProvider::colors() and how each is actually used today:
 * - CREATE_ACTION_COLOR = 'success' (green): the positive, completing
 *   action — matches this app's own existing convention for a genuine
 *   "this finishes positively" button (Approve/Mark Won/etc. across
 *   several resources already use 'success' the same way).
 * - CANCEL_ACTION_COLOR = 'danger' (red): explicitly requested — also
 *   matches Delete's own existing color everywhere else in this app, a
 *   consistent "this abandons/destroys the current action" family.
 * - CREATE_ANOTHER_ACTION_COLOR = 'slateblue': needs to differ from green,
 *   red, Back's gold, and coral (already an active "Mark Lost" signal on
 *   2 real buttons). Every other registered color was ruled out: 'navy'
 *   and 'accent' are load-bearing THEME chrome, not free action colors —
 *   'navy' is the sidebar's own permanent background hue and 'accent' is
 *   the sidebar's active-item glow / dashboard-greeting date / KPI icon
 *   badge color (see resources/css/filament/admin/theme.css) — reusing
 *   either for a button risks reading as "this matches the nav chrome",
 *   not a distinct action. 'warning' (amber) is already this app's
 *   established "corrective/caution" action color (Correct Outcome and
 *   several "Mark ..." actions across resources). 'info' is already
 *   reused across five unrelated buttons in five different resources as
 *   this app's general "neutral distinct action" color, which is exactly
 *   the role this button needs — but picking it here would make Create
 *   Another sit too close in hue to Filament's own unset-color Create
 *   button family. That leaves 'slateblue': registered
 *   (AdminPanelProvider's colors(), #5B7C99), and its only existing use
 *   anywhere in this app is a passive Lead Temperature badge ("Cold") —
 *   never an action/button color — so adopting it here creates no
 *   "this button already means X" collision, the same reasoning
 *   BACK_BUTTON_COLOR's own docblock used to adopt 'gold'.
 */
trait HasCreateFormActionColors
{
    public const CREATE_ACTION_COLOR = 'success';

    public const CREATE_ANOTHER_ACTION_COLOR = 'slateblue';

    public const CANCEL_ACTION_COLOR = 'danger';

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->color(self::CREATE_ACTION_COLOR);
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()->color(self::CREATE_ANOTHER_ACTION_COLOR);
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()->color(self::CANCEL_ACTION_COLOR);
    }
}
