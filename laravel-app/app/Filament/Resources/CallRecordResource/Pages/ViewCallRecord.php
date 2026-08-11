<?php

namespace App\Filament\Resources\CallRecordResource\Pages;

use App\Filament\Resources\CallRecordResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewCallRecord extends ViewRecord
{
    protected static string $resource = CallRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
