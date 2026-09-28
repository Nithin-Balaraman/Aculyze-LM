<?php

namespace App\Filament\Resources\CallRecordResource\Pages;

use App\Filament\Resources\CallRecordResource;
use App\Filament\Resources\ProspectResource;
use App\Models\CallRecord;
use App\Support\DeletionGuard;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCallRecord extends EditRecord
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
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()
                ->before(fn (CallRecord $record) => DeletionGuard::guardRecord($record, 'call record')),
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
