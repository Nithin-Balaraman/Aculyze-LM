<?php

namespace App\Filament\Resources\ProposalResource\Pages;

use App\Enums\ProposalLineDiscountType;
use App\Enums\ProposalTaxComponentType;
use App\Enums\ProposalVersionLifecycle;
use App\Filament\Resources\ProposalResource;
use App\Models\ProposalVersion;
use App\Policies\ProposalVersionPolicy;
use App\Services\ProposalVersionDraftService;
use App\Services\ProposalVersionWorkflowService;
use Filament\Actions;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use LogicException;

/**
 * Phase 4A-2.5: the single contextual home for a Proposal's commercial
 * ProposalVersion workflow — no global ProposalVersion Resource/navigation
 * item exists (locked product decision, section 3): a Manager works from
 * the Proposal itself. Reachable only via ViewProposal's own new
 * "Commercial Version" header action; this page is not otherwise linked or
 * navigable.
 *
 * This is ONLY an orchestration/input layer. Every authoritative
 * validation/calculation/authorization/state-transition decision is made
 * by ProposalVersionDraftService, ProposalVersionWorkflowService, and
 * ProposalVersionPolicy — this page never recomputes a formula, never
 * decides authorization itself, and never writes to ProposalVersion/Line/
 * TaxComponent directly.
 *
 * The Draft editor uses a PLAIN (non-relationship-bound) Repeater — never
 * ->relationship() — specifically so submitting the form can never trigger
 * Filament's own hidden Eloquent writes; the entire submitted array is
 * handed to ProposalVersionDraftService::saveDraft() as plain data, which
 * is the only thing that ever touches the database for a Draft save.
 */
class ManageCommercialVersion extends ViewRecord
{
    protected static string $resource = ProposalResource::class;

    protected static string $view = 'filament.resources.proposal-resource.pages.manage-commercial-version';

    public ProposalVersion $currentVersion;

    public string $concurrencyToken;

    /** @var array<string, mixed>|null */
    public ?array $draftData = [];

    /**
     * Deliberately does NOT call parent::mount() (ViewRecord::mount()):
     * that method's own hasInfolist() check calls this page's infolist()
     * to count its components, which would read $this->currentVersion
     * before refreshCurrentVersion() below ever had a chance to set it.
     * Record resolution and access authorization are replicated directly
     * from the same trait/parent methods instead.
     */
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->authorizeAccess();
        $this->refreshCurrentVersion();
    }

    private function refreshCurrentVersion(): void
    {
        $this->record = $this->record->fresh();
        $this->currentVersion = $this->record->currentVersion()->with(['lines.taxComponents'])->firstOrFail();
        $this->concurrencyToken = (string) $this->currentVersion->updated_at;

        if ($this->isDraftEditable()) {
            $this->fillDraftForm();
        }
    }

    public function isDraftEditable(): bool
    {
        return $this->currentVersion->lifecycle_status === ProposalVersionLifecycle::Draft
            && app(ProposalVersionPolicy::class)->edit(auth()->user(), $this->currentVersion);
    }

    private function fillDraftForm(): void
    {
        $this->getDraftForm()->fill([
            'customer_name_snapshot' => $this->currentVersion->customer_name_snapshot,
            'customer_gstin_snapshot' => $this->currentVersion->customer_gstin_snapshot,
            'billing_address_snapshot' => $this->currentVersion->billing_address_snapshot,
            'billing_state_snapshot' => $this->currentVersion->billing_state_snapshot,
            'place_of_supply_snapshot' => $this->currentVersion->place_of_supply_snapshot,
            'scope_notes' => $this->currentVersion->scope_notes,
            'payment_terms' => $this->currentVersion->payment_terms,
            'validity_terms' => $this->currentVersion->validity_terms,
            'currency_code' => $this->currentVersion->currency_code,
            'lines' => $this->currentVersion->lines->map(fn ($line) => [
                'item_name' => $line->item_name,
                'description' => $line->description,
                'hsn_sac' => $line->hsn_sac,
                'quantity' => $line->quantity,
                'unit' => $line->unit,
                'unit_price' => $line->unit_price,
                'discount_type' => $line->discount_type?->value,
                'discount_value' => $line->discount_value,
                'gross_amount' => $line->gross_amount,
                'discount_amount' => $line->discount_amount,
                'taxable_amount' => $line->taxable_amount,
                'tax_amount' => $line->tax_amount,
                'line_total' => $line->line_total,
                'tax_components' => $line->taxComponents->map(fn ($component) => [
                    'component_type' => $component->component_type?->value,
                    'rate' => $component->rate,
                    'amount' => $component->amount,
                ])->all(),
            ])->all(),
        ]);
    }

    public function getDraftForm(): Form
    {
        return $this->getForm('draftForm');
    }

    /**
     * @return array<string, Form>
     */
    protected function getForms(): array
    {
        return [
            'draftForm' => $this->draftForm($this->makeForm()->statePath('draftData')),
        ];
    }

    public function draftForm(Form $form): Form
    {
        return $form->schema([
            Section::make('Customer / Commercial Snapshot')
                ->columns(2)
                ->schema([
                    // Not required here: nullable at the DB level, and
                    // only Submit's own structural check (ProposalVersion
                    // WorkflowService::assertStructuralSubmitPrerequisites())
                    // requires it — an ordinary Draft save must be able to
                    // persist genuinely incremental progress.
                    TextInput::make('customer_name_snapshot')->label('Customer Name'),
                    TextInput::make('customer_gstin_snapshot')->label('GSTIN'),
                    Textarea::make('billing_address_snapshot')->label('Billing Address')->columnSpanFull(),
                    TextInput::make('billing_state_snapshot')->label('Billing State'),
                    TextInput::make('place_of_supply_snapshot')->label('Place of Supply'),
                    TextInput::make('currency_code')->label('Currency')->maxLength(3),
                    Textarea::make('payment_terms')->columnSpanFull(),
                    Textarea::make('validity_terms')->columnSpanFull(),
                    Textarea::make('scope_notes')->label('Scope Notes')->columnSpanFull(),
                ]),
            Section::make('Line Items')
                ->schema([
                    Repeater::make('lines')
                        ->label('')
                        ->addActionLabel('Add Line')
                        ->schema([
                            // Not required here (nullable at the DB level,
                            // same reasoning as customer_name_snapshot
                            // above) — quantity/unit_price below ARE
                            // required because their columns are NOT NULL,
                            // so a blank submission there would otherwise
                            // surface as a raw SQL error instead of a
                            // friendly validation message.
                            TextInput::make('item_name')->label('Item')->columnSpan(2),
                            Textarea::make('description')->rows(1)->columnSpan(2),
                            TextInput::make('hsn_sac')->label('HSN/SAC'),
                            TextInput::make('quantity')->numeric()->step(0.0001)->required(),
                            TextInput::make('unit')->label('Unit'),
                            TextInput::make('unit_price')->label('Unit Price')->numeric()->step(0.01)->required(),
                            Select::make('discount_type')
                                ->label('Discount Type')
                                ->options(ProposalLineDiscountType::class)
                                ->native(false)
                                ->live(),
                            TextInput::make('discount_value')
                                ->label('Discount Value')
                                ->numeric()
                                ->step(0.0001)
                                ->required(fn (Get $get) => filled($get('discount_type')))
                                ->visible(fn (Get $get) => filled($get('discount_type'))),
                            Placeholder::make('gross_amount')->label('Gross Amount')->content(fn (Get $get) => $get('gross_amount') ?? '—'),
                            Placeholder::make('discount_amount')->label('Discount Amount')->content(fn (Get $get) => $get('discount_amount') ?? '—'),
                            Placeholder::make('taxable_amount')->label('Taxable Amount')->content(fn (Get $get) => $get('taxable_amount') ?? '—'),
                            Placeholder::make('tax_amount')->label('Tax Amount')->content(fn (Get $get) => $get('tax_amount') ?? '—'),
                            Placeholder::make('line_total')->label('Line Total')->content(fn (Get $get) => $get('line_total') ?? '—'),
                            Repeater::make('tax_components')
                                ->label('Tax Components')
                                ->addActionLabel('Add Tax Component')
                                ->columnSpanFull()
                                ->schema([
                                    Select::make('component_type')
                                        ->label('Type')
                                        ->options(ProposalTaxComponentType::class)
                                        ->native(false)
                                        ->required(),
                                    TextInput::make('rate')->label('Rate (%)')->numeric()->step(0.0001)->required(),
                                    Placeholder::make('amount')->label('Amount')->content(fn (Get $get) => $get('amount') ?? '—'),
                                ])
                                ->columns(3)
                                ->defaultItems(0),
                        ])
                        ->columns(4)
                        ->defaultItems(0)
                        ->reorderable(false),
                ]),
        ]);
    }

    /**
     * Bound to the PROPOSAL record, not to the current ProposalVersion —
     * deliberately, and this is the whole bug fix for blank Version
     * History rows. Filament resolves every entry (including each
     * RepeatableEntry child) by data_get()-ing its ABSOLUTE state path
     * against the infolist's own record, so both sections have to address
     * real, resolvable relation paths from the Proposal:
     * "currentVersion.<column>" for the summary, and the genuine
     * versionsNewestFirst() relation for the history. The previous
     * implementation bound the infolist to the Version and gave the
     * history a synthetic "history" name with a manual ->state() closure,
     * which made every child resolve data_get($currentVersion,
     * "history.<i>.<column>") — always null, hence blank rows. Only the
     * two children that carried their own ->state() closures (Superseded
     * By, Legacy) escaped that resolution and rendered, which is exactly
     * what the manual smoke test observed.
     */
    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->record($this->record)
            ->schema([
                InfolistSection::make('Commercial Version Summary')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('currentVersion.version_number')->label('Version'),
                        TextEntry::make('currentVersion.lifecycle_status')->label('Commercial Version Status')->badge(),
                        TextEntry::make('current_or_historical')
                            ->label('Current / Frozen')
                            ->state('Current'),
                        TextEntry::make('currentVersion.is_legacy_backfill')
                            ->label('Legacy')
                            ->badge()
                            ->color('gray')
                            ->state(fn () => $this->currentVersion->is_legacy_backfill ? 'Legacy' : null)
                            ->visible(fn () => $this->currentVersion->is_legacy_backfill),
                        TextEntry::make('currentVersion.customer_name_snapshot')->label('Customer Name')->placeholder('Not available'),
                        TextEntry::make('currentVersion.customer_gstin_snapshot')->label('GSTIN')->placeholder('—'),
                        TextEntry::make('currentVersion.billing_address_snapshot')->label('Billing Address')->placeholder('—'),
                        TextEntry::make('currentVersion.billing_state_snapshot')->label('Billing State')->placeholder('—'),
                        TextEntry::make('currentVersion.place_of_supply_snapshot')->label('Place of Supply')->placeholder('—'),
                        TextEntry::make('currentVersion.payment_terms')->label('Payment Terms')->placeholder('—'),
                        TextEntry::make('currentVersion.validity_terms')->label('Validity Terms')->placeholder('—'),
                        TextEntry::make('currentVersion.scope_notes')->label('Scope Notes')->placeholder('—'),
                        TextEntry::make('currentVersion.subtotal')->label('Subtotal')->money('INR')->placeholder('Not available'),
                        TextEntry::make('currentVersion.total_discount')->label('Total Discount')->money('INR')->placeholder('Not available'),
                        TextEntry::make('currentVersion.tax_total')->label('Tax Total')->money('INR')->placeholder('Not available'),
                        TextEntry::make('currentVersion.grand_total')->label('Grand Total')->money('INR')->placeholder('Not available'),
                        TextEntry::make('currentVersion.currency_code')->label('Currency')->placeholder('—'),
                        TextEntry::make('currentVersion.submittedBy.name')->label('Submitted By')->placeholder('—'),
                        TextEntry::make('currentVersion.submitted_at')->label('Submitted At')->dateTime()->placeholder('—'),
                        TextEntry::make('currentVersion.approvedBy.name')->label('Approved By')->placeholder('—'),
                        TextEntry::make('currentVersion.approved_at')->label('Approved At')->dateTime()->placeholder('—'),
                        TextEntry::make('currentVersion.approval_comment')->label('Approval Comment')->placeholder('—'),
                        TextEntry::make('currentVersion.returnedBy.name')->label('Returned By')->placeholder('—'),
                        TextEntry::make('currentVersion.returned_at')->label('Returned At')->dateTime()->placeholder('—'),
                        TextEntry::make('currentVersion.return_reason')->label('Return Reason')->placeholder('—'),
                        TextEntry::make('currentVersion.sent_at')->label('Sent At')->date()->placeholder('—'),
                        TextEntry::make('currentVersion.superseded_at')->label('Superseded At')->dateTime()->placeholder('—'),
                    ]),
                InfolistSection::make('Version History')
                    ->description('Newest Version first. Every Version — current and historical — can be opened read-only.')
                    ->schema([
                        RepeatableEntry::make('versionsNewestFirst')
                            ->label('')
                            ->columns(4)
                            ->schema([
                                TextEntry::make('version_number')->label('Version')->formatStateUsing(fn ($state) => "V{$state}"),
                                TextEntry::make('lifecycle_status')->label('Commercial Version Status')->badge(),
                                TextEntry::make('created_at')->label('Created')->dateTime()->placeholder('—'),
                                TextEntry::make('grand_total')->label('Grand Total')->money('INR')->placeholder('—'),
                                TextEntry::make('submitted_at')->label('Submitted')->dateTime()->placeholder('—'),
                                TextEntry::make('approved_at')->label('Approved')->dateTime()->placeholder('—'),
                                TextEntry::make('returned_at')->label('Returned')->dateTime()->placeholder('—'),
                                TextEntry::make('sent_at')->label('Sent')->date()->placeholder('—'),
                                TextEntry::make('submittedBy.name')->label('Submitted By')->placeholder('—'),
                                TextEntry::make('approvedBy.name')->label('Approved By')->placeholder('—'),
                                TextEntry::make('returnedBy.name')->label('Returned By')->placeholder('—'),
                                TextEntry::make('return_reason')->label('Return Reason')->placeholder('—'),
                                TextEntry::make('approval_comment')->label('Approval Comment')->placeholder('—'),
                                TextEntry::make('superseded_by_version_id')
                                    ->label('Superseded By')
                                    ->state(fn ($record) => $record->superseded_by_version_id ? "V{$record->supersededByVersion?->version_number}" : null)
                                    ->placeholder('—'),
                                TextEntry::make('is_legacy_backfill')
                                    ->label('Legacy')
                                    ->state(fn ($record) => $record->is_legacy_backfill ? 'Legacy' : null)
                                    ->placeholder('—'),
                                TextEntry::make('open_version')
                                    ->label('Full Detail')
                                    ->state('View Version')
                                    ->color('primary')
                                    ->url(fn ($record) => ProposalResource::getUrl('commercial-version', [
                                        'record' => $this->record,
                                        'version' => $record->getKey(),
                                    ])),
                            ]),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        $version = $this->currentVersion;
        $actor = auth()->user();

        return [
            Actions\Action::make('saveDraft')
                ->label('Save Draft')
                ->icon('heroicon-o-check')
                ->visible(fn () => $this->isDraftEditable())
                ->action('saveDraft'),

            Actions\Action::make('submitVersion')
                ->label('Submit for Final Approval')
                ->color('primary')
                ->icon('heroicon-o-paper-airplane')
                ->requiresConfirmation()
                ->modalDescription('Submit this commercial Draft for final approval? It will no longer be editable unless returned for revision.')
                ->visible(fn () => $version->lifecycle_status === ProposalVersionLifecycle::Draft
                    && app(ProposalVersionPolicy::class)->submit($actor, $version))
                ->action('submitVersion'),

            Actions\Action::make('approveVersion')
                ->label('Approve')
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->form([
                    Textarea::make('approval_comment')->label('Comment (optional)'),
                ])
                ->visible(fn () => $version->lifecycle_status === ProposalVersionLifecycle::Submitted
                    && app(ProposalVersionPolicy::class)->approve($actor, $version))
                ->action(fn (array $data) => $this->approveVersion($data['approval_comment'] ?? null)),

            Actions\Action::make('returnVersion')
                ->label('Return for Revision')
                ->color('danger')
                ->icon('heroicon-o-arrow-uturn-left')
                ->form([
                    Textarea::make('return_reason')->label('Return Reason')->required(),
                ])
                ->visible(fn () => $version->lifecycle_status === ProposalVersionLifecycle::Submitted
                    && app(ProposalVersionPolicy::class)->returnForRevision($actor, $version))
                ->action(fn (array $data) => $this->returnVersion($data['return_reason'])),

            Actions\Action::make('createRevision')
                ->label('Create Revision')
                ->icon('heroicon-o-document-duplicate')
                ->visible(fn () => in_array($version->lifecycle_status, [ProposalVersionLifecycle::Approved, ProposalVersionLifecycle::Sent], true)
                    && app(ProposalVersionPolicy::class)->createRevision($actor, $version))
                ->action('createRevisionAction'),
        ];
    }

    public function saveDraft(): void
    {
        $data = $this->getDraftForm()->getState();

        try {
            app(ProposalVersionDraftService::class)->saveDraft(
                $this->currentVersion,
                auth()->user(),
                $this->concurrencyToken,
                [
                    'customer_name_snapshot' => $data['customer_name_snapshot'],
                    'customer_gstin_snapshot' => $data['customer_gstin_snapshot'],
                    'billing_address_snapshot' => $data['billing_address_snapshot'],
                    'billing_state_snapshot' => $data['billing_state_snapshot'],
                    'place_of_supply_snapshot' => $data['place_of_supply_snapshot'],
                    'scope_notes' => $data['scope_notes'],
                    'payment_terms' => $data['payment_terms'],
                    'validity_terms' => $data['validity_terms'],
                    'currency_code' => $data['currency_code'],
                ],
                collect($data['lines'] ?? [])->map(fn (array $line) => [
                    'item_name' => $line['item_name'],
                    'description' => $line['description'] ?? null,
                    'hsn_sac' => $line['hsn_sac'] ?? null,
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'] ?? null,
                    'unit_price' => $line['unit_price'],
                    'discount_type' => $line['discount_type'] ?? null,
                    'discount_value' => $line['discount_value'] ?? null,
                    'tax_components' => collect($line['tax_components'] ?? [])->map(fn (array $component) => [
                        'component_type' => $component['component_type'],
                        'rate' => $component['rate'],
                    ])->all(),
                ])->all(),
            );
        } catch (LogicException $e) {
            Notification::make()->title("Couldn't save the Draft")->body($e->getMessage())->danger()->send();

            return;
        }

        $this->refreshCurrentVersion();

        Notification::make()->title('Draft saved')->success()->send();
    }

    public function submitVersion(): void
    {
        try {
            app(ProposalVersionWorkflowService::class)->submit($this->currentVersion, auth()->user());
        } catch (LogicException $e) {
            Notification::make()->title("Couldn't submit this Version")->body($e->getMessage())->danger()->send();

            return;
        }

        $this->refreshCurrentVersion();

        Notification::make()->title('Submitted for final approval')->success()->send();
    }

    public function approveVersion(?string $comment): void
    {
        try {
            app(ProposalVersionWorkflowService::class)->approve($this->currentVersion, auth()->user(), $comment);
        } catch (LogicException $e) {
            Notification::make()->title("Couldn't approve this Version")->body($e->getMessage())->danger()->send();

            return;
        }

        $this->refreshCurrentVersion();

        Notification::make()->title('Version approved')->success()->send();
    }

    public function returnVersion(string $reason): void
    {
        try {
            $newDraft = app(ProposalVersionWorkflowService::class)->returnForRevision($this->currentVersion, auth()->user(), $reason);
        } catch (LogicException $e) {
            Notification::make()->title("Couldn't return this Version for revision")->body($e->getMessage())->danger()->send();

            return;
        }

        $this->refreshCurrentVersion();

        Notification::make()->title("Returned for revision — Version {$newDraft->version_number} is now the current Draft")->success()->send();
    }

    public function createRevisionAction(): void
    {
        try {
            $newDraft = app(ProposalVersionWorkflowService::class)->createRevision($this->currentVersion, auth()->user());
        } catch (LogicException $e) {
            Notification::make()->title("Couldn't create a revision")->body($e->getMessage())->danger()->send();

            return;
        }

        $this->refreshCurrentVersion();

        Notification::make()->title("Created Version {$newDraft->version_number} as the new current Draft")->success()->send();
    }

    public function getTitle(): string
    {
        return 'Commercial Version';
    }
}
