<?php

namespace App\Filament\Resources\DemoResource\Pages;

use App\Filament\Resources\DemoResource;
use App\Models\Demo;
use App\Support\DeletionGuard;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDemo extends EditRecord
{
    protected static string $resource = DemoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            // Same server-side blocker the list row's Delete uses, so a
            // rescheduled Demo's original cannot be deleted from here
            // either (mirrors EditLead/EditFollowUp/EditProposal).
            Actions\DeleteAction::make()
                ->before(fn (Demo $record) => DeletionGuard::guardRecord($record, 'demo')),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
