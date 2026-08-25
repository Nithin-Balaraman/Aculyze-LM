<?php

namespace App\Filament\Resources\FollowUpResource\Pages;

use App\Enums\FollowUpStatus;
use App\Filament\Resources\FollowUpResource;
use App\Models\CallRecord;
use App\Models\FollowUp;
use App\Support\DeletionGuard;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
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

    /**
     * None of `outcome`/`call_notes`/`appointment_at`/`new_follow_up_at`
     * persists on FollowUp — they only ever exist on the Call Record a
     * completion creates (see handleRecordUpdate() below). Re-opening an
     * already-Completed Follow-Up would otherwise show all four blank and
     * still demand they be re-filled just to save an unrelated edit (Status
     * stays Completed, so the form's required-when-Completed rules still
     * apply, including the outcome-driven appointment_at/new_follow_up_at
     * ones) — pre-filling from the real generatedCallRecord makes a routine
     * resave trivially valid, and actually surfaces the real data instead of
     * nothing.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if ($this->getRecord()->status === FollowUpStatus::Completed) {
            $callRecord = $this->getRecord()->generatedCallRecord;
            $data['outcome'] = $callRecord?->outcome?->value;
            $data['call_notes'] = $callRecord?->notes;
            $data['appointment_at'] = $callRecord?->appointment_at;
            $data['new_follow_up_at'] = $callRecord?->follow_up_at;
        }

        return $data;
    }

    /**
     * Mirrors the row-action "Completed" modal (FollowUpResource::table())
     * exactly, so Status = Completed behaves identically whichever entry
     * point set it: a real new Call Record is created and routed through
     * CallRoutingService (via CallRecordObserver on `created`) before the
     * Follow-Up's own status flips — only on a genuine Pending -> Completed
     * transition, never on a re-save of an already-Completed record (which
     * would otherwise create a duplicate Call Record) or a non-Pending
     * record being pushed straight to Completed (the row action only ever
     * offers Completed from Pending either). Any other case just updates
     * normally; FollowUp's own model guard is the backstop that rejects a
     * Completed status with no Call Record behind it.
     *
     * `outcome`/`call_notes`/`appointment_at`/`new_follow_up_at` are all
     * stripped before $record->update($data) — none is a FollowUp column.
     *
     * $data['status'] must be resolved via FollowUpResource::
     * resolveStatus() rather than compared directly against ->value — a
     * *live* Select-with-enum-options interaction (the user actually
     * picking Completed in the browser) hands back the real FollowUpStatus
     * instance here, not the raw string (confirmed live: a naive `===
     * ->value` comparison silently never matched after a real interaction,
     * even though it passed every automated fillForm()-based test, since
     * fillForm() bulk-sets state differently than a live update does). Same
     * root cause and same fix as FollowUpResource's own resolveStatus().
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $outcome = Arr::pull($data, 'outcome');
        $callNotes = Arr::pull($data, 'call_notes');
        $appointmentAt = Arr::pull($data, 'appointment_at');
        $newFollowUpAt = Arr::pull($data, 'new_follow_up_at');

        $isCompleting = $record->status === FollowUpStatus::Pending
            && FollowUpResource::resolveStatus($data['status'] ?? null) === FollowUpStatus::Completed;

        if ($isCompleting) {
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
        }

        $record->update($data);

        return $record;
    }

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
