<?php

namespace App\Filament\Resources\ProspectResource\Pages;

use App\Filament\Resources\ProspectResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProspect extends EditRecord
{
    protected static string $resource = ProspectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // See ProspectResource::BACK_BUTTON_CLICK_HANDLER's own
            // docblock — identical mechanism/styling as ViewProspect's own
            // Back action, shared via that one constant/method so both
            // pages can never drift apart on this.
            Actions\Action::make('back')
                ->label('Back')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('info')
                ->url(fn () => ProspectResource::getBackFallbackUrl())
                ->extraAttributes(['x-on:click' => ProspectResource::BACK_BUTTON_CLICK_HANDLER]),
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    // Return to the list instead of Filament's default (stay on this same
    // Edit page) — same destination "Cancel" already goes to (mirrors the
    // Create*.php redirect fix).
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
