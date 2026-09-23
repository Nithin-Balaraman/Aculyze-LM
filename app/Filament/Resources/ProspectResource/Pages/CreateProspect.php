<?php

namespace App\Filament\Resources\ProspectResource\Pages;

use App\Filament\Resources\ProspectResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateProspect extends CreateRecord
{
    protected static string $resource = ProspectResource::class;

    /**
     * Sole real entry point: the "New prospect" button on the Database
     * (Prospect) list — confirmed by grepping the whole app for any other
     * reference to this page/route; the separate inline "+ Create new
     * company…" modal on the Call Record form (CallRecordResource) opens
     * a form-component action, never this page.
     *
     * Same mechanism as ViewProspect/EditProspect's own Back action (see
     * ProspectResource::BACK_BUTTON_CLICK_HANDLER's docblock) — no
     * adjustment needed: CreateRecord already extends the same Resource
     * page base that composes Filament's action-mounting traits, so
     * ->extraAttributes()/->color()/->url() all behave identically here.
     *
     * No unsaved-changes warning, consistent with Edit's Back today (a
     * plain navigate-away) — and, tellingly, also consistent with
     * Filament's own built-in Cancel button in this page's form footer
     * (CreateRecord::getCancelFormAction()), which already does a plain
     * history.back()-with-fallback navigate-away with no dirty-check
     * guard either. Both buttons coexist deliberately: Cancel sits with
     * Save at the point of the actual save/abandon decision, while this
     * header Back matches the same persistent top-right spot every other
     * Prospect page already has it.
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label('Back')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color(ProspectResource::BACK_BUTTON_COLOR)
                ->url(fn () => ProspectResource::getBackFallbackUrl())
                ->extraAttributes(['x-on:click' => ProspectResource::BACK_BUTTON_CLICK_HANDLER]),
        ];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // created_by is never taken from the request — always the logged-in
        // user, so it can't be spoofed (AGENTS.md section 47).
        $data['created_by'] = auth()->id();

        return $data;
    }

    // Return to the list, not the new record's view/edit page — same
    // destination "Cancel" already goes to (Filament's default here is
    // view-then-edit-then-index).
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
