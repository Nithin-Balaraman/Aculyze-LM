<?php

namespace App\Filament\Pages\Auth;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Form;
use Filament\Pages\Auth\EditProfile as BaseEditProfile;

/**
 * Self-service avatar upload, reached via the user menu (registered through
 * ->profile() in AdminPanelProvider). Deliberately minimal — just the
 * avatar. Name/email/password editing isn't exposed anywhere else in the
 * app today (UserResource, where those fields are Admin-managed, is
 * Admin-only), so this page doesn't introduce it either.
 */
class EditProfile extends BaseEditProfile
{
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                FileUpload::make('avatar')
                    ->disk('avatars')
                    ->visibility('public')
                    ->avatar()
                    ->image(),
            ]);
    }
}
