<?php

namespace App\Filament\Resources\CallRecordResource\Pages;

use App\Filament\Resources\CallRecordResource;
use App\Filament\Resources\ProspectResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewCallRecord extends ViewRecord
{
    protected static string $resource = CallRecordResource::class;

    /**
     * Same mechanism as ProspectResource's own Back action — reused
     * verbatim (color + click handler), not a second implementation; only
     * the fallback destination is resource-specific (CallRecordResource::
     * getBackFallbackUrl() -> the Calls list). See
     * ProspectResource::BACK_BUTTON_CLICK_HANDLER's own docblock for why
     * real browser history.back() is the *primary* mechanism.
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label('Back')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color(ProspectResource::BACK_BUTTON_COLOR)
                ->url(fn () => CallRecordResource::getBackFallbackUrl())
                ->extraAttributes(['x-on:click' => ProspectResource::BACK_BUTTON_CLICK_HANDLER]),
            Actions\EditAction::make(),
        ];
    }
}
