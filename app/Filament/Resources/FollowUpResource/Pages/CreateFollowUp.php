<?php

namespace App\Filament\Resources\FollowUpResource\Pages;

use App\Enums\FollowUpStatus;
use App\Filament\Resources\FollowUpResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

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
     * (if Completed was actually requested) delegates to
     * FollowUp::completeWithCall() — the same centralized method
     * EditFollowUp::handleRecordUpdate() already calls — rather than
     * reimplementing its "create the Call Record, then flip status" steps
     * inline a second time.
     *
     * Atomicity fix: the insert-as-Pending and the completeWithCall() call
     * are both wrapped in one outer DB::transaction(). completeWithCall()
     * already opens its own transaction internally, which nests as a
     * savepoint of this outer one — so a failure anywhere in the Call
     * Record creation or the routing it triggers now rolls back the initial
     * Follow-Up insert too, instead of leaving a Pending Follow-Up behind
     * with no Call Record/Completed status to show for it.
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

        return DB::transaction(function () use ($data, $isCompletingAtCreation, $outcome, $callNotes, $appointmentAt, $newFollowUpAt) {
            $record = new ($this->getModel())($data);
            $record->save();

            if ($isCompletingAtCreation) {
                $record->completeWithCall([
                    'outcome' => $outcome,
                    'notes' => $callNotes,
                    'appointment_at' => $appointmentAt,
                    'follow_up_at' => $newFollowUpAt,
                ]);
            }

            return $record;
        });
    }

    // Return to the list, not the new record's view/edit page — same
    // destination "Cancel" already goes to (Filament's default here is
    // view-then-edit-then-index).
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
