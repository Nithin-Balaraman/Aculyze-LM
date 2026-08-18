<?php

namespace App\Filament\Resources\LeadResource\Pages;

use App\Filament\Resources\LeadResource;
use App\Models\Lead;
use App\Support\DeletionGuard;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLead extends EditRecord
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()
                ->before(fn (Lead $record) => DeletionGuard::guardRecord($record, 'lead')),
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
