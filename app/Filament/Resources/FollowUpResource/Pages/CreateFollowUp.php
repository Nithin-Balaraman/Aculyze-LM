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
     * implies. None of `outcome`/`call_notes`/`appointment_at`/
     * `new_follow_up_at` persist on FollowUp itself.
     *
     * A brand-new record has no id yet to hang a Call Record's
     * `follow_up_id` off of, so this always inserts as Pending first, then
     * (if Completed was actually requested) creates the Call Record and
     * flips status, mirroring the two-step order handleRecordUpdate()/the
     * row-action modal both already rely on.
     *
     * $data['status'] is resolved via FollowUpResource::resolveStatus()
     * rather than compared directly against ->value — see
     * EditFollowUp::handleRecordUpdate()'s identical comment for why a live
     * Select interaction hands back the enum instance, not the raw string.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $outcome = Arr::pull($data, 'outcome');
        $callNotes = Arr::pull($data, 'call_notes');
        $appointmentAt = Arr::pull($data, 'appointment_at');
        $newFollowUpAt = Arr::pull($data, 'new_follow_up_at');

        $isCompletingAtCreation = FollowUpResource::resolveStatus($data['status'] ?? null) === FollowUpStatus::Completed;

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
                'appointment_at' => $appointmentAt,
                'follow_up_at' => $newFollowUpAt,
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
