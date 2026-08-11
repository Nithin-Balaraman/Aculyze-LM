<?php

namespace App\Filament\Resources\CallRecordResource\Pages;

use App\Filament\Resources\CallRecordResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCallRecord extends EditRecord
{
    protected static string $resource = CallRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
