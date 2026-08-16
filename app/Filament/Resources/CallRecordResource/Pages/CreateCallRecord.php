<?php

namespace App\Filament\Resources\CallRecordResource\Pages;

use App\Filament\Resources\CallRecordResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCallRecord extends CreateRecord
{
    protected static string $resource = CallRecordResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Who made the call is always the logged-in user — never accepted
        // from the request (AGENTS.md section 47).
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
