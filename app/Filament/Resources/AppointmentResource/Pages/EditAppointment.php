<?php

namespace App\Filament\Resources\AppointmentResource\Pages;

use App\Filament\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Support\DeletionGuard;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Livewire\Attributes\Url;

class EditAppointment extends EditRecord
{
    protected static string $resource = AppointmentResource::class;

    // Populated from ?activeTab=... on the URL the row's Edit link built
    // (see AppointmentResource::table()) — same #[Url] mechanism
    // ListRecords itself uses for its own activeTab. Null for a direct/
    // bookmarked edit URL with nothing to carry.
    #[Url]
    public ?string $activeTab = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            // Same server-side blocker the list row's Delete uses, so a
            // rescheduled Appointment's original cannot be deleted from
            // here either (mirrors EditLead/EditFollowUp/EditProposal).
            Actions\DeleteAction::make()
                ->before(fn (Appointment $record) => DeletionGuard::guardRecord($record, 'appointment')),
        ];
    }

    // Return to the list instead of Filament's default (stay on this same
    // Edit page) — same destination "Cancel" already goes to (mirrors the
    // Create*.php redirect fix). Reattaches $activeTab so the user lands
    // back on History/Lost instead of always Pending; falls back to
    // today's plain-index behavior when it's null.
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index', array_filter([
            'activeTab' => $this->activeTab,
        ]));
    }
}
