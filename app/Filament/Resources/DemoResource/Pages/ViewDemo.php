<?php

namespace App\Filament\Resources\DemoResource\Pages;

use App\Filament\Resources\DemoResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewDemo extends ViewRecord
{
    protected static string $resource = DemoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
