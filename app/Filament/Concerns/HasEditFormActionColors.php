<?php

namespace App\Filament\Concerns;

use Filament\Actions\Action;

/**
 * Edit-page counterpart to HasCreateFormActionColors: colors an EditRecord
 * page's two footer actions so Save reads as the same positive/finishing
 * action Create already does, and Cancel reads as the same abandon/discard
 * action it does everywhere else, rather than Filament's own defaults
 * (Save: unset -> falls through to plain brand `primary`; Cancel: plain
 * `gray`, indistinguishable from a merely secondary action).
 *
 * A separate trait rather than composing HasCreateFormActionColors in
 * directly: that trait's own method overrides (getCreateFormAction()/
 * getCreateAnotherFormAction()) call parent:: methods that exist on
 * CreateRecord but not on EditRecord, so `use`-ing it into an EditRecord
 * page would compile but fatal the moment Filament actually invoked the
 * inherited getCreateFormAction() override, since EditRecord has no such
 * parent method to forward to.
 *
 * SAVE_ACTION_COLOR/CANCEL_ACTION_COLOR are literal 'success'/'danger' —
 * not a reference to HasCreateFormActionColors::CREATE_ACTION_COLOR/
 * CANCEL_ACTION_COLOR — because PHP does not allow a trait to read
 * another trait's constant directly (only a concrete class that `use`s it
 * can; confirmed directly: `Cannot access trait constant ... directly`).
 * This is a different situation from ExportActions::COLOR/'slateblue': that
 * was one arbitrarily-chosen brand color that genuinely needed a single
 * source of truth to avoid two independent picks drifting apart. 'success'
 * and 'danger' are fixed, built-in Filament semantic keywords, not a
 * project-specific choice — the same way Filament's own DeleteAction
 * independently hard-codes 'danger' in its own defaultColor() with no
 * shared constant, and nobody treats that as at-risk duplication.
 *
 * VIEW_ACTION_COLOR is a separate concern (a page HEADER action, not a
 * form footer action, so it can't be wired via a method override the way
 * Save/Cancel are) — exposed here as a constant so any Edit page still
 * needs only one line for it (->color(HasEditFormActionColors::
 * VIEW_ACTION_COLOR) on its ViewAction), matching the BACK_BUTTON_COLOR
 * precedent. Chosen as 'primary': the twin View page's own Edit header
 * action is left completely unset, which Filament's HasColor::getColor()
 * falls through to null -> renders with the panel's own brand `primary`
 * (confirmed by reading vendor/filament/actions/src/EditAction.php: no
 * ->color()/->defaultColor() call at all), while ViewAction's OWN default
 * (vendor/filament/actions/src/ViewAction.php) is `->defaultColor('gray')`
 * — the two would only accidentally match if View here were also left
 * unset, which it isn't (ViewAction's own gray default would still win).
 * Setting 'primary' explicitly is what actually makes View-on-Edit-page
 * and Edit-on-View-page read as a deliberate pair, and cleanly differs
 * from 'success' (Save), 'danger' (Cancel/Delete), 'gold' (Back), and
 * 'slateblue' (Create & Create Another / Import / Export).
 */
trait HasEditFormActionColors
{
    public const SAVE_ACTION_COLOR = 'success';

    public const CANCEL_ACTION_COLOR = 'danger';

    public const VIEW_ACTION_COLOR = 'primary';

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->color(self::SAVE_ACTION_COLOR);
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()->color(self::CANCEL_ACTION_COLOR);
    }
}
