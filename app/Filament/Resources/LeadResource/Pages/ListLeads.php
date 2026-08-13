<?php

namespace App\Filament\Resources\LeadResource\Pages;

use App\Enums\ExportableResource;
use App\Filament\Resources\LeadResource;
use App\Support\Exports\ExportActions;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLeads extends ListRecords
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ExportActions::immediate(ExportableResource::Lead),
            ExportActions::request(ExportableResource::Lead),
            Actions\CreateAction::make(),
        ];
    }
}
