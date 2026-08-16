<?php

namespace App\Filament\Pages\Auth;

use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Auth\EditProfile as BaseEditProfile;

/**
 * Self-service profile page, reached via the user menu (registered through
 * ->profile() in AdminPanelProvider). Deliberately minimal — avatar and
 * password only. Name/email editing isn't exposed anywhere else in the app
 * today (UserResource, where those fields are Admin-managed, is
 * Admin-only), so this page doesn't introduce it either.
 *
 * Password is the one field that genuinely belongs here rather than only
 * in UserResource: this is "change your own password" (Admin and Employee
 * alike), a distinct flow from Admin setting *someone else's* password via
 * Employees -> edit, which stays exactly as it was. getPasswordFormComponent()
 * / getPasswordConfirmationFormComponent() are inherited from the base
 * EditProfile page and already handle hashing, "leave blank to keep the
 * current password", and confirmation-matching — the only piece Filament
 * doesn't ship is verifying the *current* password first, which is Laravel's
 * own current_password validation rule, not a Filament one.
 */
class EditProfile extends BaseEditProfile
{
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Avatar')
                    ->schema([
                        FileUpload::make('avatar')
                            ->label('')
                            ->disk('avatars')
                            ->visibility('public')
                            ->avatar()
                            ->image(),
                    ]),
                Section::make('Change Password')
                    ->description('Leave these blank to keep your current password.')
                    ->schema([
                        TextInput::make('current_password')
                            ->label('Current Password')
                            ->password()
                            ->revealable(filament()->arePasswordsRevealable())
                            ->autocomplete('current-password')
                            ->requiredWith('password')
                            ->rule('current_password:' . Filament::getAuthGuard())
                            ->dehydrated(false),
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                    ]),
            ]);
    }

    /**
     * Mirrors the base page's own password/passwordConfirmation reset after
     * a successful save (see EditProfile::save()) — current_password is
     * never dehydrated/persisted, but without this it would still linger in
     * the form's client-side state after saving.
     */
    protected function afterSave(): void
    {
        $this->data['current_password'] = null;
    }
}
