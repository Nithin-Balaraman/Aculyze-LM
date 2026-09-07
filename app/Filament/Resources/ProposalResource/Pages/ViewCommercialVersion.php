<?php

namespace App\Filament\Resources\ProposalResource\Pages;

use App\Filament\Resources\ProposalResource;
use App\Models\ProposalVersion;
use Filament\Actions;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

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

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->record($this->getVersionRecord())
            ->schema([
                InfolistSection::make('Identity / State')
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
                    ]),
                InfolistSection::make('Customer Snapshot')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('customer_name_snapshot')->label('Customer Name')->placeholder('Not available'),
                        TextEntry::make('customer_gstin_snapshot')->label('GSTIN')->placeholder('—'),
                        TextEntry::make('billing_state_snapshot')->label('Billing State')->placeholder('—'),
                        TextEntry::make('billing_address_snapshot')->label('Billing Address')->placeholder('—'),
                        TextEntry::make('place_of_supply_snapshot')->label('Place of Supply')->placeholder('—'),
                    ]),
                InfolistSection::make('Commercial Content')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('payment_terms')->label('Payment Terms')->placeholder('—'),
                        TextEntry::make('validity_terms')->label('Validity Terms')->placeholder('—'),
                        TextEntry::make('scope_notes')->label('Scope Notes')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('currency_code')->label('Currency')->placeholder('—'),
                    ]),
                InfolistSection::make('Totals')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('subtotal')->label('Subtotal')->money('INR')->placeholder('Not available'),
                        TextEntry::make('total_discount')->label('Total Discount')->money('INR')->placeholder('Not available'),
                        TextEntry::make('tax_total')->label('Tax Total')->money('INR')->placeholder('Not available'),
                        TextEntry::make('grand_total')->label('Grand Total')->money('INR')->placeholder('Not available'),
                    ]),
                InfolistSection::make('Workflow Evidence')
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
                    ]),
                InfolistSection::make('Line Items')
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
                    ]),
            ]);
    }
}
