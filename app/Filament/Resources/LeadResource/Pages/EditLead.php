<?php

namespace App\Filament\Resources\LeadResource\Pages;

use App\Filament\Resources\LeadResource;
use App\Models\Lead;
use App\Support\DeletionGuard;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Livewire\Attributes\Url;

class EditLead extends EditRecord
{
    protected static string $resource = LeadResource::class;

    // Populated from ?activeTab=... on the URL the row's Edit link built
    // (see LeadResource::table()) — same #[Url] mechanism ListRecords
    // itself uses for its own activeTab. Null for a direct/bookmarked
    // edit URL with nothing to carry.
    #[Url]
    public ?string $activeTab = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()
                ->before(fn (Lead $record) => DeletionGuard::guardRecord($record, 'lead')),
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
