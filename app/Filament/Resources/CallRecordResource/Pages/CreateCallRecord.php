<?php

namespace App\Filament\Resources\CallRecordResource\Pages;

use App\Filament\Resources\CallRecordResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateCallRecord extends CreateRecord
{
    protected static string $resource = CallRecordResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Who made the call is always the logged-in user — never accepted
        // from the request (AGENTS.md section 47).
        $data['user_id'] = auth()->id();

        return $data;
    }

    /**
     * Atomicity fix: CallRecordObserver::created() -> CallRoutingService::
     * route() opens its OWN DB::transaction() the moment this Call Record is
     * saved. Without an outer transaction already open here, that insert
     * commits independently of whatever routing does afterward — a routing
     * failure would then leave a real, "processed_at still null" Call
     * Record behind despite the user seeing a failure. Wrapping the
     * (otherwise-default) record creation in one outer transaction makes
     * the Observer's nested transaction a savepoint of this same
     * connection transaction, so a routing failure now rolls back the Call
     * Record insert too — the same composition FollowUp::completeWithCall()
     * already relies on.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(fn () => parent::handleRecordCreation($data));
    }

    // Return to the list, not the new record's view/edit page — same
    // destination "Cancel" already goes to (Filament's default here is
    // view-then-edit-then-index).
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
