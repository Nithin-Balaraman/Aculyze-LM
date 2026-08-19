<?php

namespace App\Filament\Resources\FollowUpResource\Pages;

use App\Filament\Resources\FollowUpResource;
use App\Models\FollowUp;
use App\Support\DeletionGuard;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Livewire\Attributes\Url;

class EditFollowUp extends EditRecord
{
    protected static string $resource = FollowUpResource::class;

    // Populated from ?activeTab=... on the URL the row's Edit link built
    // (see FollowUpResource::table()) — same #[Url] mechanism ListRecords
    // itself uses for its own activeTab. Null for a direct/bookmarked
    // edit URL with nothing to carry.
    #[Url]
    public ?string $activeTab = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            // Mirrors the row-level table action exactly (same admin-only
            // visibility, same cascade-check-then-guard ->before()) — this
            // used to have neither: any user could reach this button, and
            // deleting a Follow-Up with a real dependent raw-threw a 500
            // instead of the friendly DeletionGuard message, since nothing
            // here ever called DeletionGuard::guardRecord().
            Actions\DeleteAction::make()
                ->visible(fn () => auth()->user()->isAdmin())
                ->before(function (FollowUp $record) {
                    $record->deleteHarmlessGeneratedCallRecord();
                    DeletionGuard::guardRecord($record, 'follow-up');
                }),
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
