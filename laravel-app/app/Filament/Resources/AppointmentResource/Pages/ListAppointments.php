<?php

namespace App\Filament\Resources\AppointmentResource\Pages;

use App\Enums\AppointmentStage;
use App\Filament\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Support\CsvExporter;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;

class ListAppointments extends ListRecords
{
    protected static string $resource = AppointmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportCsv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn () => auth()->user()->isAdmin())
                ->form([
                    Forms\Components\Select::make('stage')
                        ->label('Appointment Stage')
                        ->options(AppointmentStage::class)
                        ->placeholder('All stages'),
                ])
                ->action(function (array $data) {
                    $query = Appointment::query()->with(['prospect', 'assignedEmployee', 'creator']);

                    if (filled($data['stage'] ?? null)) {
                        $query->where('stage', $data['stage']);
                    }

                    $rows = $query->orderBy('created_at')->get()->map(fn (Appointment $appointment) => [
                        $appointment->prospect->company_name,
                        $appointment->prospect->contact_person,
                        $appointment->stage->getLabel(),
                        $appointment->appointment_at?->format('Y-m-d H:i'),
                        $appointment->assignedEmployee->name,
                        $appointment->creator->name,
                        $appointment->stage_changed_at?->format('Y-m-d H:i'),
                        $appointment->created_at->format('Y-m-d H:i'),
                    ]);

                    $suffix = filled($data['stage'] ?? null) ? AppointmentStage::from($data['stage'])->getLabel() : 'All Stages';

                    return CsvExporter::stream(
                        "appointments-{$suffix}-".now()->format('Y-m-d').'.csv',
                        ['Company', 'Contact Person', 'Stage', 'Appointment At', 'Assigned Employee', 'Created By', 'Stage Since', 'Created At'],
                        $rows,
                    );
                }),
            Actions\CreateAction::make(),
        ];
    }
}
