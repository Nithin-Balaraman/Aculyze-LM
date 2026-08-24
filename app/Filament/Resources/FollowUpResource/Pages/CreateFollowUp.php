<?php

namespace App\Filament\Resources\FollowUpResource\Pages;

use App\Enums\FollowUpStatus;
use App\Filament\Resources\FollowUpResource;
use App\Models\CallRecord;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreateFollowUp extends CreateRecord
{
    protected static string $resource = FollowUpResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }

    /**
     * Follow-Ups are normally auto-routed as Pending, but the shared form
     * (FollowUpResource::form()) does expose Status, so someone could pick
     * Completed right here — kept consistent with EditFollowUp's own
     * handling rather than allowed to skip the Call Record/routing that
     * implies. `outcome`/`call_notes` never persist on FollowUp itself.
     *
     * A brand-new record has no id yet to hang a Call Record's
     * `follow_up_id` off of, and FollowUp's own model guard rejects saving
     * Completed with no Call Record behind it — so this always inserts as
     * Pending first, then (if Completed was actually requested) creates the
     * Call Record and flips status, mirroring the two-step order
     * handleRecordUpdate()/the row-action modal both already rely on.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $outcome = Arr::pull($data, 'outcome');
        $callNotes = Arr::pull($data, 'call_notes');

        $isCompletingAtCreation = ($data['status'] ?? null) === FollowUpStatus::Completed->value;

        if ($isCompletingAtCreation) {
            $data['status'] = FollowUpStatus::Pending->value;
        }

        $record = new ($this->getModel())($data);
        $record->save();

        if ($isCompletingAtCreation) {
            CallRecord::create([
                'prospect_id' => $record->prospect_id,
                'user_id' => auth()->id(),
                'called_at' => now(),
                'outcome' => $outcome,
                'notes' => $callNotes,
                'follow_up_id' => $record->id,
            ]);

            $record->update(['status' => FollowUpStatus::Completed]);
        }

        return $record;
    }

    // Return to the list, not the new record's view/edit page — same
    // destination "Cancel" already goes to (Filament's default here is
    // view-then-edit-then-index).
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
