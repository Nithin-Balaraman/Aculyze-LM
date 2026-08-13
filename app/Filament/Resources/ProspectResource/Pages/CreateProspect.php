<?php

namespace App\Filament\Resources\ProspectResource\Pages;

use App\Filament\Resources\ProspectResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProspect extends CreateRecord
{
    protected static string $resource = ProspectResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // created_by is never taken from the request — always the logged-in
        // user, so it can't be spoofed (AGENTS.md section 47).
        $data['created_by'] = auth()->id();

        return $data;
    }
}
