<?php

namespace App\Filament\Resources;

use App\Enums\ProposalOutcome;
use App\Enums\ProposalStage;
use App\Filament\Resources\ProposalResource\Pages;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\User;
use App\Support\TableBulkActions;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Proposal processing (AGENTS.md sections 24-27, 44). Stage names/order are
 * provisional — see App\Enums\ProposalStage. One Proposal per Lead for this
 * initial version (enforced by a unique DB constraint on leads.id, see
 * AGENTS.md section 61 Question 7).
 */
class ProposalResource extends Resource
{
    protected static ?string $model = Proposal::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Proposals';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 6;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make()
                    ->columns(2)
                    ->schema([
                        // whereDoesntHave('proposal') is what keeps
                        // already-claimed Leads out of the options list on
                        // Create — but on Edit/View, this field's own
                        // record's Lead already has a Proposal (this one!),
                        // so without the orWhere() escape hatch below, this
                        // Select's own current value was excluded from the
                        // very query used to resolve its display label —
                        // Filament's async getFormSelectOptionLabel() call
                        // came back null, and the JS fell back to showing
                        // the raw lead_id instead of the company name.
                        Forms\Components\Select::make('lead_id')
                            ->label('Lead')
                            ->relationship(
                                'lead',
                                'id',
                                modifyQueryUsing: fn (Builder $query, ?Proposal $record) => $query
                                    ->visibleTo(auth()->user())
                                    ->where(
                                        fn (Builder $query) => $query
                                            ->whereDoesntHave('proposal')
                                            ->when($record, fn (Builder $query) => $query->orWhere(
                                                $query->getModel()->getQualifiedKeyName(),
                                                $record->lead_id,
                                            ))
                                    ),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Lead $record) => $record->prospect->company_name.' — '.$record->stage->getLabel())
                            ->required()
                            ->searchable()
                            ->preload()
                            ->disabled(fn (?Proposal $record) => $record !== null)
                            ->dehydrated(),
                        Forms\Components\Select::make('assigned_to')
                            ->label('Assigned Employee')
                            ->options(fn () => User::query()->pluck('name', 'id'))
                            ->default(fn () => auth()->id())
                            ->required()
                            ->searchable()
                            ->disabled(fn () => ! auth()->user()->isAdmin())
                            ->dehydrated(),
                        // ->live() so pdf_path's visible()/required() below
                        // react the moment Stage changes — same mechanism as
                        // CallRecordResource's outcome-driven fields and
                        // LeadResource's stage-driven Notes requirement.
                        Forms\Components\Select::make('stage')
                            ->options(ProposalStage::class)
                            ->required()
                            ->default(ProposalStage::BeingPrepared)
                            ->live(),
                        Forms\Components\Select::make('outcome')
                            ->label('Final Outcome')
                            ->options(ProposalOutcome::class)
                            ->helperText('Leave blank while the Proposal is still in progress.'),
                        Forms\Components\TextInput::make('value')
                            ->label('Proposal Value (₹)')
                            ->numeric()
                            ->prefix('₹'),
                        Forms\Components\DatePicker::make('sent_at'),
                        Forms\Components\Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull(),
                        // Required the moment Stage is "Proposal Sent" — on
                        // every save, including an already-Sent Proposal
                        // from before this field existed, the next time it's
                        // opened and saved (deliberate: no backfill/grandfathering).
                        // Stored on the 'local' disk (private, already
                        // signature-protected by Laravel's own storage.local
                        // route — see config/filesystems.php) rather than
                        // the public 'avatars' disk. previewable(false)
                        // because Filament's private-file preview link calls
                        // $storage->temporaryUrl(), which the stock local
                        // Flysystem adapter doesn't support — it would throw
                        // and silently fall back to a plain unsigned URL
                        // that the signature-checking route would then
                        // reject. Viewing/downloading instead goes through
                        // downloadPdfAction() below, which streams the file
                        // directly and rides the same page-level
                        // authorization Filament already applies to reach
                        // this Resource's Edit/View pages.
                        Forms\Components\FileUpload::make('pdf_path')
                            ->label('Proposal PDF')
                            ->disk('local')
                            ->directory('proposal-pdfs')
                            ->visibility('private')
                            ->acceptedFileTypes(['application/pdf'])
                            ->maxSize(10240)
                            ->previewable(false)
                            ->columnSpanFull()
                            // Visible once Sent OR once a PDF already
                            // exists — so a Proposal that moves on to a
                            // later stage (Customer Accepted, or a Lost
                            // outcome) doesn't lose sight of the PDF it
                            // already has; required-ness is untouched and
                            // still keys only off Sent.
                            ->visible(fn (Get $get) => self::stageIsSent($get('stage')) || filled($get('pdf_path')))
                            ->required(fn (Get $get) => self::stageIsSent($get('stage')))
                            ->validationMessages([
                                'required' => 'A PDF must be uploaded once the Proposal stage is Proposal Sent.',
                            ])
                            // visible() above depends on pdf_path's own
                            // value, so removing the file (clearing it to
                            // empty) can itself make the field hidden in
                            // the same save (stage moved away from Sent at
                            // the same time) — and Filament's fields don't
                            // dehydrate while hidden by default, which
                            // would silently keep the stale path in the
                            // database. dehydratedWhenHidden() makes sure
                            // the cleared state still overwrites pdf_path
                            // regardless of visibility at save time.
                            ->dehydratedWhenHidden()
                            // Filament's FileUpload does NOT delete the
                            // underlying stored file when removed via the
                            // form's own "x" button unless this is set —
                            // without it, clicking "x" only detaches the
                            // reference, leaving the file itself orphaned
                            // on the 'local' disk indefinitely.
                            ->deleteUploadedFileUsing(function (string|TemporaryUploadedFile $file): void {
                                if (is_string($file)) {
                                    Storage::disk('local')->delete($file);
                                }
                            }),
                    ]),
            ]);
    }

    /**
     * $get() may hand back either the raw string value or the hydrated
     * ProposalStage case depending on how the form state got there — see
     * LeadResource::stageIsValidated()'s docblock for the same nuance.
     */
    private static function stageIsSent(mixed $stage): bool
    {
        return ($stage instanceof ProposalStage ? $stage : ProposalStage::tryFrom((string) $stage)) === ProposalStage::Sent;
    }

    /**
     * Shared by ViewProposal's and EditProposal's header actions. Streams
     * the file straight from the 'local' disk rather than relying on
     * Filament's own private-file preview link (see the pdf_path field's
     * comment in form() for why that link doesn't work out of the box for
     * this disk) — and since this only ever runs from inside this
     * Resource's own Edit/View pages, it automatically rides the same
     * ProposalPolicy::view() / getEloquentQuery() scoping already gating
     * access to those pages, with no separate authorization check needed
     * here.
     */
    public static function downloadPdfAction(): Actions\Action
    {
        return Actions\Action::make('downloadPdf')
            ->label('Download PDF')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (Proposal $record) => filled($record->pdf_path))
            ->action(fn (Proposal $record) => Storage::disk('local')->download(
                $record->pdf_path,
                self::pdfDownloadFilename($record),
            ));
    }

    /**
     * "{Company Name} - {Proposal ID}.pdf" — the ID is the Proposal's own
     * database id (no separate reference system), and the company name is
     * free text, so it's sanitized here since a raw '/' or similarly
     * filesystem-unsafe character would otherwise end up in a saved
     * filename (Symfony's Content-Disposition header encoding already
     * makes the HTTP response itself safe for any UTF-8 text — this is
     * purely about what the browser actually names the saved file).
     */
    private static function pdfDownloadFilename(Proposal $record): string
    {
        // Characters invalid in Windows filenames (also the ones most
        // likely to confuse a browser's own "save as" naming) become a
        // space; collapses any run of whitespace that creates (including
        // ones already in the company name) down to one before trimming.
        $companyName = trim(preg_replace(
            '/\s+/',
            ' ',
            preg_replace('/[\/\\\\:*?"<>|]+/', ' ', $record->prospect->company_name),
        ));

        return "{$companyName} - {$record->getKey()}.pdf";
    }

    /**
     * Shown on the Edit/View pages (as the page subheading, next to the
     * main heading) so the Proposal's own database ID is visible before
     * ever downloading its PDF — the same ID used in
     * pdfDownloadFilename() above, not a separate reference number.
     */
    public static function recordSubheading(Proposal $record): string
    {
        return "Proposal #{$record->getKey()}";
    }

    /**
     * Extracted from table() so the Prospect View page's Proposals
     * mini-table (see App\Filament\Widgets\ProspectProposalsTable) can
     * reuse the exact same column set rather than duplicating it —
     * matches the ProspectResource::formSchema() precedent for form
     * fields.
     *
     * @return array<int, Tables\Columns\Column>
     */
    public static function columns(): array
    {
        return [
            Tables\Columns\TextColumn::make('prospect.company_name')
                ->label('Company')
                ->searchable()
                ->sortable(),
            Tables\Columns\TextColumn::make('stage')
                ->badge()
                ->sortable(),
            Tables\Columns\TextColumn::make('outcome')
                ->badge()
                ->placeholder('In Progress')
                ->sortable(),
            Tables\Columns\TextColumn::make('value')
                ->label('Value')
                ->money('INR')
                ->placeholder('—')
                ->sortable(),
            Tables\Columns\TextColumn::make('assignedEmployee.name')
                ->label('Assigned To')
                ->badge()
                ->sortable(),
            Tables\Columns\TextColumn::make('stage_changed_at')
                ->label('Stage Since')
                ->dateTime('d M Y')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\IconColumn::make('is_stale')
                ->label('Stale')
                ->boolean()
                ->getStateUsing(fn (Proposal $record) => $record->isStale())
                ->trueColor('coral')
                ->trueIcon('heroicon-o-exclamation-triangle')
                ->falseIcon('heroicon-o-check-circle')
                ->falseColor('gray')
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(static::columns())
            ->filters([
                Tables\Filters\SelectFilter::make('stage')
                    ->options(ProposalStage::class),
                Tables\Filters\SelectFilter::make('outcome')
                    ->options(ProposalOutcome::class),
                Tables\Filters\SelectFilter::make('assigned_to')
                    ->label('Assigned Employee')
                    ->relationship('assignedEmployee', 'name')
                    ->visible(fn () => auth()->user()->isAdmin()),
                Tables\Filters\Filter::make('stale')
                    ->label('Stale only (20+ days, no movement)')
                    ->query(fn (Builder $query) => $query->stale()),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    // Carries the tab the user is currently on through to
                    // the Edit page as ?activeTab=..., so getRedirectUrl()
                    // on EditProposal can send them back to the same tab
                    // (History/Lost) instead of always Pending — see that
                    // page for the other half of this.
                    Tables\Actions\EditAction::make()
                        ->url(fn (Proposal $record, $livewire): string => static::getUrl('edit', [
                            'record' => $record,
                            'activeTab' => $livewire->activeTab,
                        ])),
                    Tables\Actions\Action::make('assign')
                        ->label('Reassign')
                        ->icon('heroicon-o-user-plus')
                        ->color('gray')
                        ->visible(fn (Proposal $record) => auth()->user()->can('assign', $record))
                        ->form([
                            Forms\Components\Select::make('assigned_to')
                                ->label('Assign To')
                                ->options(fn () => User::query()->pluck('name', 'id'))
                                ->required()
                                ->searchable(),
                        ])
                        ->action(fn (Proposal $record, array $data) => $record->update(['assigned_to' => $data['assigned_to']])),
                    Tables\Actions\DeleteAction::make()
                        ->visible(fn () => auth()->user()->isAdmin()),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    TableBulkActions::deselectAll(),
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()->isAdmin()),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No proposals in motion.')
            ->emptyStateDescription('Once a Lead is validated, send it to Proposal from its "Create Proposal" action.')
            ->emptyStateIcon('heroicon-o-document-text');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProposals::route('/'),
            'create' => Pages\CreateProposal::route('/create'),
            'view' => Pages\ViewProposal::route('/{record}'),
            'edit' => Pages\EditProposal::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }
}
