<?php

namespace App\Filament\Resources\ProposalResource\Pages;

use App\Enums\ProposalPdfArtifactStatus;
use App\Filament\Resources\ProposalResource;
use App\Models\ProposalPdfArtifact;
use App\Models\ProposalVersion;
use App\Policies\ProposalPdfArtifactPolicy;
use App\Support\Filament\SectionTabs;
use Filament\Actions;
use Filament\Infolists\Components\Actions\Action as InfolistAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\Tabs\Tab;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 4A-2.5 bug fix: a strictly READ-ONLY view of ONE ProposalVersion —
 * current or historical — in full, including its own frozen lines and tax
 * components. Without this, a Version became unreachable through the UI the
 * moment a newer Version superseded it, so a user could not verify from the
 * application that (for example) an Approved V1 really did stay Approved
 * and keep its exact commercial content after Create Revision.
 *
 * Still contextual under Proposal (no global ProposalVersion Resource or
 * navigation item): reachable only from the Version History list on
 * ManageCommercialVersion.
 *
 * Authorization: the Proposal itself is authorized exactly as every other
 * Proposal page is (ProposalPolicy::view() via the resource's own
 * canView()/getEloquentQuery() hierarchy + tenant scoping), and the
 * Version is then resolved THROUGH that Proposal's own versions()
 * relationship — so a forged ?version= id belonging to another Proposal or
 * another organization simply does not resolve (404), rather than being
 * rendered. No new authorization surface is introduced.
 *
 * Deliberately exposes NO actions: no Edit, no Submit/Approve/Return/
 * Create Revision, no lifecycle mutation, no line/tax editing. Those all
 * remain on ManageCommercialVersion, against the CURRENT Version only.
 */
class ViewCommercialVersion extends ViewRecord
{
    protected static string $resource = ProposalResource::class;

    /**
     * Only the id is component state. The Version itself is re-resolved
     * THROUGH $this->record->versions() on every request (see
     * getVersionRecord()), so a Livewire hydration can never re-attach a
     * Version belonging to a different Proposal or organization — the
     * relationship scope is re-applied every single time, not just once at
     * mount.
     */
    public int|string $versionId;

    private ?ProposalVersion $resolvedVersion = null;

    /**
     * $version is optional purely so this stays signature-compatible with
     * ViewRecord::mount(); the route always supplies it, and it is
     * required in practice (a missing one resolves to null and fails the
     * lookup below).
     *
     * Does not call parent::mount() for the same reason
     * ManageCommercialVersion does not: ViewRecord::mount()'s own
     * hasInfolist() probe builds this page's infolist, which would read
     * the Version before it is available.
     */
    public function mount(int|string $record, int|string|null $version = null): void
    {
        $this->record = $this->resolveRecord($record);
        $this->authorizeAccess();

        $this->versionId = $version ?? request()->route('version');

        // Resolve once now so an unrelated/forged version id 404s on the
        // way in rather than at first render.
        $this->getVersionRecord();
    }

    public function getVersionRecord(): ProposalVersion
    {
        return $this->resolvedVersion ??= $this->record
            ->versions()
            ->with(['lines.taxComponents'])
            ->whereKey($this->versionId)
            ->firstOrFail();
    }

    public function getTitle(): string
    {
        return "Commercial Version V{$this->getVersionRecord()->version_number}";
    }

    public function getSubheading(): ?string
    {
        return $this->isCurrentVersion()
            ? 'Current Version — read-only view'
            : 'Historical Version — frozen, read-only';
    }

    public function isCurrentVersion(): bool
    {
        return $this->record->current_version_id === $this->getVersionRecord()->getKey();
    }

    public function canAccessPdfSection(): bool
    {
        return app(ProposalPdfArtifactPolicy::class)->downloadPdf(auth()->user(), $this->getVersionRecord());
    }

    /**
     * Every PDF artifact attempt against THIS Version, newest first —
     * Success and Failed alike, permanent history. Read-only: this page
     * exposes no way to generate, correct, or otherwise mutate any of it —
     * see the class docblock's structural rule.
     */
    public function pdfArtifacts(): \Illuminate\Support\Collection
    {
        return ProposalPdfArtifact::query()
            ->where('proposal_version_id', $this->getVersionRecord()->getKey())
            ->orderByDesc('generated_at')
            ->get();
    }

    /** Mirrors ManageCommercialVersion::downloadPdfArtifact() — same private-disk streaming pattern, never a public URL. */
    public function downloadPdfArtifact(?ProposalPdfArtifact $artifact): mixed
    {
        if ($artifact === null || $artifact->storage_path === null) {
            Notification::make()->title("This PDF isn't available to download.")->danger()->send();

            return null;
        }

        $filename = "{$this->record->proposal_number}-V{$this->getVersionRecord()->version_number}-{$artifact->getKey()}.pdf";

        return Storage::disk('local')->download($artifact->storage_path, $filename);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('backToCommercialVersion')
                ->label('Back to Current Commercial Version')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->url(fn () => ProposalResource::getUrl('commercial', ['record' => $this->record])),
        ];
    }

    /**
     * Phase 4A-3.7 (UI/navigation pass only — no business-logic change):
     * this page's 9 sections used to render stacked one after another.
     * Consolidated into 6 tabs — Overview groups the 4 always-small,
     * non-repeatable "what is this Version" sections (Identity/State,
     * Customer Snapshot, Commercial Content, Totals) that were never long
     * enough on their own to need separate tabs; the remaining tabs keep
     * their own original section 1:1. Every section's own content,
     * visibility condition, and description are carried over unchanged —
     * see SectionTabs's own docblock for why the tab-level ->visible()
     * below is safe (mirrors, never replaces, each section's existing
     * authorization) and correct even where an earlier tab is hidden.
     * The single header action (Back to Current Commercial Version) lives
     * in getHeaderActions() below, entirely outside this infolist.
     */
    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->record($this->getVersionRecord())
            ->schema([
                SectionTabs::make('commercial-version-detail', [
                    Tab::make('Overview')->schema([
                        $this->identitySection(),
                        $this->customerSnapshotSection(),
                        $this->commercialContentSection(),
                        $this->totalsSection(),
                    ]),
                    Tab::make('Workflow Evidence')->schema([$this->workflowEvidenceSection()]),
                    Tab::make('PDF History')
                        ->visible(fn () => $this->canAccessPdfSection())
                        ->schema([$this->pdfArtifactHistorySection()]),
                    Tab::make('Release & Send')->schema([$this->releaseSendSection()]),
                    Tab::make('Client Responses')
                        ->visible(fn () => $this->getVersionRecord()->clientResponses()->exists())
                        ->schema([$this->clientResponsesSection()]),
                    Tab::make('Line Items')->schema([$this->lineItemsSection()]),
                ]),
            ]);
    }

    private function identitySection(): InfolistSection
    {
        return InfolistSection::make('Identity / State')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('version_number')->label('Version')->formatStateUsing(fn ($state) => "V{$state}"),
                        TextEntry::make('lifecycle_status')->label('Commercial Version Status')->badge(),
                        TextEntry::make('current_or_historical')
                            ->label('Current / Frozen')
                            ->state(fn () => $this->isCurrentVersion() ? 'Current' : 'Historical'),
                        TextEntry::make('is_legacy_backfill')
                            ->label('Legacy')
                            ->state(fn ($record) => $record->is_legacy_backfill ? 'Legacy' : null)
                            ->placeholder('—'),
                    ]);
    }

    private function customerSnapshotSection(): InfolistSection
    {
        return InfolistSection::make('Customer Snapshot')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('customer_name_snapshot')->label('Customer Name')->placeholder('Not available'),
                        TextEntry::make('customer_gstin_snapshot')->label('GSTIN')->placeholder('—'),
                        TextEntry::make('billing_state_snapshot')->label('Billing State')->placeholder('—'),
                        TextEntry::make('billing_address_snapshot')->label('Billing Address')->placeholder('—'),
                        TextEntry::make('place_of_supply_snapshot')->label('Place of Supply')->placeholder('—'),
                    ]);
    }

    private function commercialContentSection(): InfolistSection
    {
        return InfolistSection::make('Commercial Content')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('payment_terms')->label('Payment Terms')->placeholder('—'),
                        TextEntry::make('validity_terms')->label('Validity Terms')->placeholder('—'),
                        TextEntry::make('scope_notes')->label('Scope Notes')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('currency_code')->label('Currency')->placeholder('—'),
                    ]);
    }

    private function totalsSection(): InfolistSection
    {
        return InfolistSection::make('Totals')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('subtotal')->label('Subtotal')->money('INR')->placeholder('Not available'),
                        TextEntry::make('total_discount')->label('Total Discount')->money('INR')->placeholder('Not available'),
                        TextEntry::make('tax_total')->label('Tax Total')->money('INR')->placeholder('Not available'),
                        TextEntry::make('grand_total')->label('Grand Total')->money('INR')->placeholder('Not available'),
                    ]);
    }

    private function workflowEvidenceSection(): InfolistSection
    {
        return InfolistSection::make('Workflow Evidence')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('submittedBy.name')->label('Submitted By')->placeholder('—'),
                        TextEntry::make('submitted_at')->label('Submitted At')->dateTime()->placeholder('—'),
                        TextEntry::make('approvedBy.name')->label('Approved By')->placeholder('—'),
                        TextEntry::make('approved_at')->label('Approved At')->dateTime()->placeholder('—'),
                        TextEntry::make('approval_comment')->label('Approval Comment')->placeholder('—'),
                        TextEntry::make('returnedBy.name')->label('Returned By')->placeholder('—'),
                        TextEntry::make('returned_at')->label('Returned At')->dateTime()->placeholder('—'),
                        TextEntry::make('return_reason')->label('Return Reason')->placeholder('—'),
                        TextEntry::make('sent_at')->label('Sent At')->date()->placeholder('—'),
                        TextEntry::make('superseded_at')->label('Superseded At')->dateTime()->placeholder('—'),
                        TextEntry::make('supersededByVersion.version_number')
                            ->label('Superseded By')
                            ->formatStateUsing(fn ($state) => "V{$state}")
                            ->placeholder('—'),
                    ]);
    }

    private function pdfArtifactHistorySection(): InfolistSection
    {
        return InfolistSection::make('Final PDF Artifact History')
                    ->visible(fn () => $this->canAccessPdfSection())
                    ->description('Every generation attempt against this exact Version — permanent, read-only. No action here mutates anything.')
                    ->schema([
                        RepeatableEntry::make('pdfArtifacts')
                            ->label('')
                            ->columns(5)
                            ->schema([
                                TextEntry::make('status')->label('Status')->badge(),
                                TextEntry::make('generated_at')->label('Generated At')->dateTime()->placeholder('—'),
                                TextEntry::make('generatedBy.name')->label('Generated By')->placeholder('—'),
                                TextEntry::make('template_version')->label('Template')->placeholder('—'),
                                TextEntry::make('current_or_superseded')
                                    ->label('State')
                                    ->badge()
                                    ->state(fn ($record) => $record->status === ProposalPdfArtifactStatus::Success
                                        ? ($record->superseded_at === null ? 'Current Primary' : 'Superseded')
                                        : null)
                                    ->placeholder('—'),
                                TextEntry::make('checksum_sha256')->label('Checksum (SHA-256)')->placeholder('—')->columnSpanFull(),
                                TextEntry::make('correction_reason')->label('Correction Reason')->placeholder('—')->columnSpanFull()
                                    ->visible(fn ($record) => filled($record->correction_reason)),
                                TextEntry::make('failure_reason')->label('Failure Reason')->placeholder('—')->columnSpanFull()
                                    ->visible(fn ($record) => $record->status === ProposalPdfArtifactStatus::Failed),
                                TextEntry::make('download')
                                    ->label('')
                                    ->state('Download')
                                    ->color('primary')
                                    ->visible(fn ($record) => $record->status === ProposalPdfArtifactStatus::Success && $this->canAccessPdfSection())
                                    ->action(
                                        InfolistAction::make('downloadHistoricalPdf')
                                            ->action(fn ($record) => $this->downloadPdfArtifact($record))
                                    ),
                            ]),
                    ]);
    }

    private function releaseSendSection(): InfolistSection
    {
        return InfolistSection::make('Release & Send Evidence')
                    ->description('Read-only historical evidence — no Release or Send action exists on this page (Phase 4A-3.3, locked Section E).')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('released_at')->label('Released At')->dateTime()->placeholder('—'),
                        TextEntry::make('releasedBy.name')->label('Released By')->placeholder('—'),
                        TextEntry::make('release_comment')->label('Release Comment')->placeholder('—')->columnSpanFull(),
                    ]);
    }

    private function clientResponsesSection(): InfolistSection
    {
        return InfolistSection::make('Client Responses to This Version')
                    ->description('Read-only historical evidence — no Record Client Response action exists on this page (Phase 4A-3.4).')
                    ->visible(fn () => $this->getVersionRecord()->clientResponses()->exists())
                    ->schema([
                        RepeatableEntry::make('clientResponses')
                            ->label('')
                            ->columns(4)
                            ->schema([
                                TextEntry::make('response_type')->label('Response')->badge(),
                                TextEntry::make('recorded_at')->label('Recorded At')->dateTime(),
                                TextEntry::make('recordedBy.name')->label('Recorded By')->placeholder('—'),
                                TextEntry::make('next_action')->label('Next Action')->badge()->placeholder('—'),
                                TextEntry::make('reason')->label('Reason')->placeholder('—')->columnSpanFull(),
                                TextEntry::make('notes')->label('Notes')->placeholder('—')->columnSpanFull(),
                            ]),
                    ]);
    }

    private function lineItemsSection(): InfolistSection
    {
        return InfolistSection::make('Line Items')
                    ->description('Exactly the lines and tax components frozen on this Version.')
                    ->schema([
                        RepeatableEntry::make('lines')
                            ->label('')
                            ->columns(4)
                            ->schema([
                                TextEntry::make('line_number')->label('#'),
                                TextEntry::make('item_name')->label('Item')->placeholder('—'),
                                TextEntry::make('hsn_sac')->label('HSN/SAC')->placeholder('—'),
                                TextEntry::make('unit')->label('Unit')->placeholder('—'),
                                TextEntry::make('description')->label('Description')->placeholder('—')->columnSpanFull(),
                                TextEntry::make('quantity')->label('Quantity')->placeholder('—'),
                                TextEntry::make('unit_price')->label('Unit Price')->money('INR')->placeholder('—'),
                                TextEntry::make('discount_type')->label('Discount Type')->placeholder('—'),
                                TextEntry::make('discount_value')->label('Discount Value')->placeholder('—'),
                                TextEntry::make('gross_amount')->label('Gross')->money('INR')->placeholder('—'),
                                TextEntry::make('discount_amount')->label('Discount Amount')->money('INR')->placeholder('—'),
                                TextEntry::make('taxable_amount')->label('Taxable')->money('INR')->placeholder('—'),
                                TextEntry::make('tax_amount')->label('Tax')->money('INR')->placeholder('—'),
                                TextEntry::make('line_total')->label('Line Total')->money('INR')->placeholder('—'),
                                RepeatableEntry::make('taxComponents')
                                    ->label('Tax Components')
                                    ->columnSpanFull()
                                    ->columns(3)
                                    ->schema([
                                        TextEntry::make('component_type')->label('Type')->badge(),
                                        TextEntry::make('rate')->label('Rate (%)'),
                                        TextEntry::make('amount')->label('Amount')->money('INR'),
                                    ]),
                            ]),
                    ]);
    }
}
