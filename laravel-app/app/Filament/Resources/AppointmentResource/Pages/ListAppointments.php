<?php

namespace App\Filament\Resources\AppointmentResource\Pages;

use App\Enums\ExportableResource;
use App\Filament\Resources\AppointmentResource;
use App\Support\Exports\ExportActions;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAppointments extends ListRecords
{
    protected static string $resource = AppointmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ExportActions::immediate(ExportableResource::Appointment),
            ExportActions::request(ExportableResource::Appointment),
            Actions\CreateAction::make(),
        ];
    }
}
