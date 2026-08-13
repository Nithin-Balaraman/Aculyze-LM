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
}
