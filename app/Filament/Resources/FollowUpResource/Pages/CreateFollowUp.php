<?php

namespace App\Filament\Resources\FollowUpResource\Pages;

use App\Filament\Resources\FollowUpResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFollowUp extends CreateRecord
{
    protected static string $resource = FollowUpResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }

    // Return to the list, not the new record's view/edit page — same
    // destination "Cancel" already goes to (Filament's default here is
    // view-then-edit-then-index).
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
