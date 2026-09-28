<?php

namespace App\Filament\Resources\CallRecordResource\Pages;

use App\Enums\CallOutcome;
use App\Filament\Resources\CallRecordResource;
use App\Filament\Resources\ProspectResource;
use App\Models\CallRecord;
use Filament\Actions;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

/**
 * Calls Phase 3: a real infolist for this page, replacing Filament's
 * default fallback (rendering the Edit page's own form schema, disabled —
 * see vendor/filament/filament/src/Resources/Pages/ViewRecord::infolist(),
 * whose base implementation is just `return $infolist;` with no schema,
 * which is what made Filament reach for the resource's form() instead: see
 * vendor/filament/filament/src/Resources/Pages/Concerns/
 * InteractsWithRecord — there is no such fallback for infolist(); once
 * this method is overridden, the disabled form never renders again).
 *
 * Defined here on the page — not as a CallRecordResource::infolist()
 * static method — matching the established convention for every other
 * read-only view page in this app: ViewProspect, ViewCommercialVersion,
 * and ManageCommercialVersion all define infolist() on the page class
 * itself, not the Resource (grepped for "function infolist" across every
 * Resource and Page: it appears only on Page classes, never once on a
 * Resource). This infolist is used nowhere else (unlike form()/
 * formSchema(), which PipelineBoard's dialog genuinely reuses), so there's
 * no reuse pressure pulling it onto the Resource either.
 *
 * Same 4-section grouping the Create/Edit form already uses (Call
 * Details/Contact & Follow-Up/Profile Sent/Notes & Review), same field
 * order, same conditional visibility rules (Next Action only for Others,
 * the whole Profile Sent section only for Profile Requested, correction/
 * flag fields only once actually set) — this is a pure read-only
 * presentation of the exact same data the form already collects, not a
 * new view of it. ->inlineLabel() on each Section is Filament's own
 * built-in label-left/value-right row layout (see ViewProspect::
 * companyDetailsSection()'s own docblock, commit d37bbbe, for the full
 * mechanism explanation) — the same pattern, reused here rather than
 * reinvented.
 */
class ViewCallRecord extends ViewRecord
{
    protected static string $resource = CallRecordResource::class;

    /**
     * Same mechanism as ProspectResource's own Back action — reused
     * verbatim (color + click handler), not a second implementation; only
     * the fallback destination is resource-specific (CallRecordResource::
     * getBackFallbackUrl() -> the Calls list). See
     * ProspectResource::BACK_BUTTON_CLICK_HANDLER's own docblock for why
     * real browser history.back() is the *primary* mechanism.
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label('Back')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color(ProspectResource::BACK_BUTTON_COLOR)
                ->url(fn () => CallRecordResource::getBackFallbackUrl())
                ->extraAttributes(['x-on:click' => ProspectResource::BACK_BUTTON_CLICK_HANDLER]),
            Actions\EditAction::make(),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                $this->callDetailsSection(),
                $this->contactAndFollowUpSection(),
                $this->profileSentSection(),
                $this->notesAndReviewSection(),
            ]);
    }

    private function callDetailsSection(): Section
    {
        return Section::make('Call Details')
            ->inlineLabel()
            ->schema([
                TextEntry::make('prospect.company_name')->label('Company'),
                TextEntry::make('called_at')->label('Called At')->dateTime('d M Y, h:i A'),
                TextEntry::make('caller.name')->label('Called By'),
                // ->badge() auto-derives both the label and the color from
                // CallOutcome's own HasLabel/HasColor implementation
                // (Filament\Infolists\Components\Concerns\HasColor::
                // getColor() falls back to $state->getColor() whenever the
                // state itself implements the HasColor contract, and
                // CanFormatState does the equivalent for HasLabel) — the
                // exact same enum-driven badge the Calls list table's own
                // outcome column already renders (CallRecordResource::
                // columns()), not a second color/label mapping to keep in
                // sync.
                TextEntry::make('outcome')->badge(),
                TextEntry::make('next_action')
                    ->label('Next Action')
                    ->placeholder('—')
                    ->visible(fn (CallRecord $record) => $record->outcome === CallOutcome::Others),
            ]);
    }

    private function contactAndFollowUpSection(): Section
    {
        return Section::make('Contact & Follow-Up')
            ->inlineLabel()
            ->schema([
                TextEntry::make('contact_person_spoken_to')->label('Contact Person')->placeholder('—'),
                TextEntry::make('designation')->placeholder('—'),
                TextEntry::make('phone_called')->label('Phone Called')->placeholder('—'),
                TextEntry::make('follow_up_at')->label('Follow Up At')->dateTime('d M Y, h:i A')->placeholder('—'),
                TextEntry::make('appointment_at')->label('Appointment At')->dateTime('d M Y, h:i A')->placeholder('—'),
            ]);
    }

    /** Visible only for outcome Profile Requested — mirrors CallRecordResource::profileSentFieldsSchema()'s own Section visibility exactly. */
    private function profileSentSection(): Section
    {
        return Section::make('Profile Sent')
            ->inlineLabel()
            ->visible(fn (CallRecord $record) => $record->outcome === CallOutcome::ProfileRequested)
            ->schema([
                TextEntry::make('profile_sent_status')->placeholder('—'),
                TextEntry::make('profile_sent_mode')->label('Mode')->placeholder('—'),
                TextEntry::make('profile_sent_at')->label('Sent At')->dateTime('d M Y, h:i A')->placeholder('—'),
                TextEntry::make('profile_sent_notes')
                    ->label('Notes')
                    ->placeholder('—')
                    ->extraAttributes(['style' => 'white-space: pre-line']),
            ]);
    }

    /**
     * Notes is unconditional (every Call has this field); the four
     * correction/flag fields are each independently visible only once
     * actually set — a Call may have been corrected, flagged, both, or
     * neither, and these are two separate optional events (see
     * CallRecordResource::correctOutcomeAction()/flagAsIncorrectAction()),
     * not a single combined state.
     */
    private function notesAndReviewSection(): Section
    {
        return Section::make('Notes & Review')
            ->inlineLabel()
            ->schema([
                // Imported/typed Notes may contain real newlines (see
                // ViewProspect::companyDetailsSection()'s own docblock for
                // the full white-space: pre-line rationale — same
                // reasoning applies verbatim here) — a plain TextEntry
                // would otherwise collapse them into a single line.
                TextEntry::make('notes')
                    ->placeholder('—')
                    ->extraAttributes(['style' => 'white-space: pre-line']),
                TextEntry::make('correction_reason')
                    ->label('Correction Reason')
                    ->visible(fn (CallRecord $record) => filled($record->correction_reason)),
                TextEntry::make('outcome_corrected_at')
                    ->label('Outcome Corrected At')
                    ->dateTime('d M Y, h:i A')
                    ->visible(fn (CallRecord $record) => filled($record->outcome_corrected_at)),
                TextEntry::make('flag_reason')
                    ->label('Flag Reason')
                    ->visible(fn (CallRecord $record) => filled($record->flag_reason)),
                TextEntry::make('flagged_incorrect_at')
                    ->label('Flagged At')
                    ->dateTime('d M Y, h:i A')
                    ->visible(fn (CallRecord $record) => filled($record->flagged_incorrect_at)),
            ]);
    }
}
