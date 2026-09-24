<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ProspectResource;
use App\Models\Prospect;
use App\Models\User;
use Filament\Actions;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\WithFileUploads;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Excel (.xlsx) -> Prospect (Database) import wizard, open to both Admin
 * and Employee (Import Access + Export Approval batch, Section 1). Built
 * as a plain custom Livewire flow rather than Filament's declarative
 * Form/Wizard components, because the column-mapping step has to be
 * generated dynamically from whatever headers the uploaded file actually
 * has, and the duplicate-resolution step is an interactive per-row review
 * — neither fits a static form schema well.
 *
 * Despite the source data being called "Lead ID" etc. in the legacy sheet,
 * this imports into Prospect (the Database/master list) — see AGENTS.md
 * section 10 and the Decision 5 note that this data really describes
 * Prospects, not mid-funnel Leads.
 *
 * Not shown in the main navigation — reached via the "Import from Excel"
 * header action on the Database (Prospect) list page.
 */
class ImportProspects extends Page
{
    use WithFileUploads;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static string $view = 'filament.pages.import-prospects';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Import Prospects from Excel';

    /**
     * Each target field's own technical name and current UI label (see
     * TARGET_OPTIONS below) are ALWAYS recognized as auto-mapping guesses
     * — mirroring Filament's own Import feature (Filament\Actions\Imports\
     * ImportColumn::getGuesses(), which builds its guess list from a
     * column's name + label the same way) rather than inventing a new
     * mechanism. This replaces the previous single hardcoded
     * FIELD_SUGGESTIONS dictionary, whose keys were actually the LEGACY
     * SHEET's own historical column phrasings ('contact number', 'mobile
     * number', 'email address', 'full location', 'source sheet') — not
     * this app's own field labels. A user typing the label they see in
     * this very page's dropdown ("Telephone", "Mobile", "Email",
     * "Address", "Source") got silently defaulted to Notes, because that
     * literal text was never in the dictionary. See guessesForField()
     * and guessFieldFor() below for the actual matching logic.
     *
     * @var array<string, string>
     */
    private const FIELD_LABELS = [
        'company_name' => 'Company Name',
        'contact_person' => 'Contact Person',
        'telephone' => 'Telephone',
        'mobile' => 'Mobile',
        'email' => 'Email',
        'website' => 'Website',
        'industry' => 'Industry',
        'city' => 'City',
        'address' => 'Address',
        'source' => 'Source',
        'assigned_owner' => 'Assigned Owner',
    ];

    /**
     * Additional historical phrasings worth recognizing alongside a
     * field's own name/label, so the original legacy sheet format (see
     * ImportProspectsTest::LEGACY_HEADERS) keeps auto-mapping correctly —
     * these are extra guesses, not the ONLY recognized phrasing (that
     * exclusivity was the bug).
     *
     * @var array<string, array<int, string>>
     */
    private const FIELD_LEGACY_GUESSES = [
        'telephone' => ['Contact Number'],
        'mobile' => ['Mobile Number'],
        'email' => ['Email Address'],
        'address' => ['Full Location'],
        'source' => ['Source Sheet'],
    ];

    /**
     * @var array<string, string>
     */
    public const TARGET_OPTIONS = [
        'ignore' => '— Ignore —',
        'company_name' => 'Company Name (required)',
        'contact_person' => 'Contact Person',
        'telephone' => 'Telephone',
        'mobile' => 'Mobile',
        'email' => 'Email',
        'website' => 'Website',
        'industry' => 'Industry',
        'city' => 'City',
        'address' => 'Address',
        'source' => 'Source',
        'assigned_owner' => 'Assigned Owner (matched by employee name)',
        'notes' => 'Include in Notes',
    ];

    public $file = null;

    public string $step = 'upload';

    public string $uploadError = '';

    /**
     * Raw sheet rows exactly as loaded from the workbook, before any row
     * has been confirmed as the real header row. Kept around (rather than
     * immediately shifting off row 1, as the previous implementation
     * did) so the header-row confirmation step can preview several rows
     * and the admin can pick whichever one actually holds the column
     * names — the previous code always treated physical row 1 as headers
     * unconditionally, with no detection or confirmation at all, which
     * silently misread an instructional/title row above the real headers.
     *
     * @var array<int, array<int, string>>
     */
    public array $sheetRows = [];

    /** @var int 0-based index into $sheetRows the admin has confirmed contains the real column headers */
    public int $headerRowIndex = 0;

    /** @var array<int, string> */
    public array $headers = [];

    /** @var array<int, array<string, string>> */
    public array $rows = [];

    /** @var array<string, string> header => target field */
    public array $mapping = [];

    /** @var array<int, string> headers whose destination was confidently auto-recognized (not defaulted to Notes) */
    public array $autoMatchedHeaders = [];

    /** @var array<int, array<string, mixed>> */
    public array $pendingDuplicates = [];

    /** @var array<int, string> index => 'update'|'new'|'skip' */
    public array $duplicateResolutions = [];

    public string $bulkResolution = '';

    /** @var array{total: int, imported: int, updated: int, addedDespiteDuplicate: int, skipped: int, failed: array<int, array{row: int, reason: string}>, warnings: array<int, array{row: int, messages: array<int, string>}>} */
    public array $summary = [
        'total' => 0,
        'imported' => 0,
        'updated' => 0,
        'addedDespiteDuplicate' => 0,
        'skipped' => 0,
        'failed' => [],
        'warnings' => [],
    ];

    public function mount(): void
    {
        // Any authenticated user (Admin or Employee) may import — this page
        // is standalone (not nested under ProspectResource's own admin-only
        // policies/authorization), so opening it here doesn't widen any
        // other Prospect permission.
        abort_unless(auth()->check(), 403);
    }

    /**
     * Sole real entry point: the "Import from Excel" header action on the
     * Database (Prospect) list — confirmed by this page's own class
     * docblock and by grepping the app for any other reference to this
     * route. Same mechanism as every other Prospect page's Back action
     * (see ProspectResource::BACK_BUTTON_CLICK_HANDLER's docblock) — no
     * adjustment needed: this plain Filament\Pages\Page already composes
     * the same action-mounting traits (Filament\Pages\BasePage implements
     * HasActions via InteractsWithActions) that ViewRecord/EditRecord/
     * CreateRecord build on, and its Blade view already renders through
     * the standard <x-filament-panels::page> wrapper that displays header
     * actions, so nothing else needed changing to make this render here.
     *
     * Deliberately exits the ENTIRE flow (browser history.back(), landing
     * on the Database list) rather than stepping back one internal wizard
     * stage — backToUpload()/backToMapping() below already own "go back
     * one stage within the import," wired to their own dedicated buttons
     * at each step; giving this header-level Back a second, different
     * meaning ("step back one stage") would collide with those and read
     * as two inconsistent behaviors both labeled "Back" on the same page.
     * No unsaved-progress warning, consistent with every other Prospect
     * page's Back today (a plain navigate-away). Checked what's actually
     * at stake rather than assuming: processMapping() already writes
     * every non-duplicate row to the database immediately, before the
     * user ever reaches the duplicates/summary step — those rows are
     * never at risk. The one real thing Back can abandon mid-flow is a
     * batch of *unresolved* duplicates (flagged for the interactive
     * per-row review, but not yet updated/added) — those simply never
     * get imported if left unresolved, same as if the user just closed
     * the tab; nothing partially-written or corrupted either way.
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

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function processUpload(): void
    {
        $this->uploadError = '';

        if (! $this->file) {
            $this->uploadError = 'Please choose a file.';

            return;
        }

        $extension = strtolower($this->file->getClientOriginalExtension());

        if ($extension !== 'xlsx') {
            $this->uploadError = 'Only .xlsx files are supported.';

            return;
        }

        try {
            $path = $this->file->store('imports');
            $fullPath = Storage::path($path);
            $spreadsheet = IOFactory::load($fullPath);
            $sheet = $spreadsheet->getActiveSheet();
            $data = $sheet->toArray(null, true, true, false);
        } catch (\Throwable) {
            $this->uploadError = 'This file could not be read. Make sure it is a valid, unprotected .xlsx workbook.';

            return;
        } finally {
            if (isset($path)) {
                Storage::delete($path);
            }
        }

        if (count($data) === 0) {
            $this->uploadError = 'This workbook has no data.';

            return;
        }

        $this->sheetRows = $data;
        $this->headerRowIndex = 0;
        $this->step = 'header-row';
    }

    /**
     * Up to the first 3 raw rows, for the header-row confirmation step's
     * preview — deliberately raw (un-trimmed, un-filtered, un-shifted) so
     * what the admin sees matches exactly what's physically in the
     * workbook, since the whole point of this step is letting them judge
     * which row is the real header row before any cleanup happens.
     *
     * @return array<int, array<int, string>>
     */
    public function previewRows(): array
    {
        return array_slice($this->sheetRows, 0, 3);
    }

    /**
     * Finishes what processUpload() used to do unconditionally against
     * row 1 — trims/filters the confirmed header row, disambiguates
     * duplicate header names, builds the row data and the auto-mapping
     * suggestions — now parameterized on whichever row index the admin
     * picked in the header-row confirmation step.
     */
    public function confirmHeaderRow(): void
    {
        $this->uploadError = '';

        if (! array_key_exists($this->headerRowIndex, $this->sheetRows)) {
            $this->uploadError = 'Choose which row contains your column headers.';

            return;
        }

        $headerRow = array_map(fn ($h) => trim((string) $h), $this->sheetRows[$this->headerRowIndex]);
        $headerRow = array_values(array_filter($headerRow, fn ($h) => $h !== ''));

        if ($headerRow === []) {
            $this->uploadError = 'No column headers were found in the selected row.';

            return;
        }

        // Two columns sharing an identical header (e.g. two "Notes" columns)
        // would otherwise collide once used as array/mapping keys below,
        // silently losing whichever column processed first. Disambiguate so
        // every source column keeps its own row and its own data.
        $seenHeaders = [];
        $headerRow = array_map(function (string $header) use (&$seenHeaders) {
            $seenHeaders[$header] = ($seenHeaders[$header] ?? 0) + 1;

            return $seenHeaders[$header] > 1 ? "{$header} (#{$seenHeaders[$header]})" : $header;
        }, $headerRow);

        $data = array_slice($this->sheetRows, $this->headerRowIndex + 1);
        $dataRows = array_filter($data, fn ($row) => collect($row)->contains(fn ($v) => trim((string) $v) !== ''));

        if ($dataRows === []) {
            $this->uploadError = 'The rows below the selected header row have no data.';

            return;
        }

        $this->headers = $headerRow;
        $this->rows = collect($dataRows)->values()->map(function ($row) use ($headerRow) {
            $assoc = [];
            foreach ($headerRow as $i => $header) {
                $assoc[$header] = trim((string) ($row[$i] ?? ''));
            }

            return $assoc;
        })->all();

        $this->mapping = collect($headerRow)->mapWithKeys(function (string $header) {
            return [$header => self::guessFieldFor($header) ?? 'notes'];
        })->all();

        // Tracked separately from the mapping itself so the UI can badge
        // "not auto-matched" columns without that badge disappearing the
        // moment the admin manually maps one to Notes on purpose.
        $this->autoMatchedHeaders = collect($headerRow)
            ->filter(fn (string $header) => self::guessFieldFor($header) !== null)
            ->values()
            ->all();

        $this->step = 'mapping';
    }

    /**
     * Strips both the ASCII whitespace PHP's own trim() already handles
     * AND Unicode "separator" space characters (\p{Z} — covers U+00A0
     * no-break space, U+2000-200A, U+202F, U+3000, etc.) plus the
     * zero-width / byte-order-mark characters that are equally invisible
     * copy-paste artifacts despite not being classified as whitespace
     * (U+200B zero-width space, U+FEFF BOM). A plain trim() only strips
     * the classic " \t\n\r\0\x0B" set — this was the confirmed root
     * cause of a seemingly-exact-match header (e.g. "Company Name" with a
     * trailing non-breaking space, a common Word/web copy-paste artifact)
     * silently failing to auto-map.
     */
    private static function normalizeHeader(string $header): string
    {
        $stripped = preg_replace('/^[\s\p{Z}\x{200B}\x{FEFF}]+|[\s\p{Z}\x{200B}\x{FEFF}]+$/u', '', $header);

        return Str::lower($stripped ?? $header);
    }

    /**
     * Filament-Importer-style guess list for one target field (mirrors
     * Filament\Actions\Imports\ImportColumn::getGuesses()): its own
     * technical name, its current UI label, and any legacy phrasing —
     * each normalized and expanded with '-'/'_'/space treated as
     * interchangeable, so "Contact Number", "contact_number" and
     * "contact-number" are all recognized as equivalent guesses.
     *
     * @return array<int, string>
     */
    private static function guessesForField(string $field): array
    {
        $candidates = array_merge(
            [$field, self::FIELD_LABELS[$field] ?? $field],
            self::FIELD_LEGACY_GUESSES[$field] ?? [],
        );

        return array_reduce($candidates, function (array $carry, string $candidate): array {
            $normalized = self::normalizeHeader($candidate);
            $spaced = str_replace(['-', '_'], ' ', $normalized);

            $carry[] = $normalized;
            $carry[] = $spaced;

            if (str_contains($spaced, ' ')) {
                $carry[] = str_replace(' ', '-', $spaced);
                $carry[] = str_replace(' ', '_', $spaced);
            }

            return $carry;
        }, []);
    }

    /**
     * @return string|null the Prospect field this spreadsheet header
     * confidently auto-matches, or null to fall back to the Notes
     * catch-all (existing behavior, unchanged).
     */
    private static function guessFieldFor(string $header): ?string
    {
        $normalizedHeader = self::normalizeHeader($header);

        foreach (array_keys(self::FIELD_LABELS) as $field) {
            if (in_array($normalizedHeader, self::guessesForField($field), true)) {
                return $field;
            }
        }

        return null;
    }

    public function backToUpload(): void
    {
        $this->step = 'upload';
        $this->file = null;
        $this->sheetRows = [];
        $this->headerRowIndex = 0;
        $this->headers = [];
        $this->rows = [];
        $this->mapping = [];
        $this->autoMatchedHeaders = [];
    }

    /**
     * Lets the admin return to the header-row confirmation step to pick a
     * different row, without losing the uploaded workbook. Headers/rows/
     * mapping are discarded since they must be rebuilt from whichever row
     * the admin lands on next; sheetRows/headerRowIndex are deliberately
     * kept so the previously-picked row stays selected rather than
     * resetting back to row 1.
     */
    public function backToHeaderRow(): void
    {
        $this->step = 'header-row';
        $this->headers = [];
        $this->rows = [];
        $this->mapping = [];
        $this->autoMatchedHeaders = [];
    }

    /**
     * Lets the admin return to Step 2 to correct a mapping mistake spotted
     * while reviewing duplicates, without losing the uploaded workbook or
     * re-triggering auto-mapping. Pending duplicate state is discarded since
     * it must be recomputed against whatever mapping they land on next.
     */
    public function backToMapping(): void
    {
        $this->step = 'mapping';
        $this->pendingDuplicates = [];
        $this->duplicateResolutions = [];
        $this->bulkResolution = '';
    }

    public function processMapping(): void
    {
        if (! in_array('company_name', $this->mapping, true)) {
            $this->uploadError = 'Map at least one column to Company Name before continuing — it is required.';

            return;
        }

        $this->uploadError = '';
        $this->summary = ['total' => count($this->rows), 'imported' => 0, 'updated' => 0, 'addedDespiteDuplicate' => 0, 'skipped' => 0, 'failed' => [], 'warnings' => []];
        $this->pendingDuplicates = [];

        $usersByName = User::query()->get()->keyBy(fn (User $user) => Str::lower(trim($user->name)));

        foreach ($this->rows as $i => $row) {
            $attributes = [];
            $noteLines = [];
            $assignedOwnerRaw = null;

            foreach ($this->mapping as $header => $target) {
                $value = trim((string) ($row[$header] ?? ''));

                if ($value === '' || $target === 'ignore') {
                    continue;
                }

                match ($target) {
                    'assigned_owner' => $assignedOwnerRaw = $value,
                    'notes' => $noteLines[] = "{$header}: {$value}",
                    default => $attributes[$target] = $value,
                };
            }

            if (blank($attributes['company_name'] ?? null)) {
                $this->summary['failed'][] = ['row' => $i + 2, 'reason' => 'Missing required Company Name'];

                continue;
            }

            $warnings = [];
            $assignedTo = null;

            // Employee imports always attribute to the importing employee —
            // the sheet's Assigned Owner column (effectively untrusted
            // input, just like a hidden form field) must never be able to
            // hand a newly created Prospect to someone else. Admin behavior
            // is unchanged: honor a matched Assigned Owner name, falling
            // back to the importing admin.
            if ($assignedOwnerRaw && auth()->user()->isAdmin()) {
                $match = $usersByName->get(Str::lower(trim($assignedOwnerRaw)));

                if ($match) {
                    $assignedTo = $match->id;
                } else {
                    $warnings[] = "Assigned Owner \"{$assignedOwnerRaw}\" did not match any employee — defaulted to you; reassign later if needed.";
                }
            } elseif ($assignedOwnerRaw) {
                $warnings[] = "Assigned Owner \"{$assignedOwnerRaw}\" was ignored — imports you perform are always assigned to you.";
            }

            $assignedTo ??= auth()->id();

            if ($noteLines !== []) {
                $attributes['notes'] = "Imported from legacy sheet:\n".implode("\n", $noteLines);
            }

            $attributes['assigned_to'] = $assignedTo;
            $attributes['created_by'] = auth()->id();

            $duplicate = $this->findDuplicate($attributes);

            if ($duplicate) {
                $this->pendingDuplicates[] = [
                    'rowNumber' => $i + 2,
                    'incoming' => $attributes,
                    'existingId' => $duplicate->id,
                    'existingSnapshot' => [
                        'company_name' => $duplicate->company_name,
                        'contact_person' => $duplicate->contact_person,
                        'telephone' => $duplicate->telephone,
                        'email' => $duplicate->email,
                        'assigned_to' => $duplicate->assignedEmployee?->name,
                    ],
                    'warnings' => $warnings,
                ];

                continue;
            }

            DB::transaction(fn () => Prospect::create($attributes));
            $this->summary['imported']++;

            if ($warnings !== []) {
                $this->summary['warnings'][] = ['row' => $i + 2, 'messages' => $warnings];
            }
        }

        $this->step = $this->pendingDuplicates !== [] ? 'duplicates' : 'summary';
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function findDuplicate(array $attributes): ?Prospect
    {
        $hasSignal = filled($attributes['telephone'] ?? null) || filled($attributes['email'] ?? null);

        if (! $hasSignal) {
            return null;
        }

        return Prospect::query()
            ->whereRaw('LOWER(TRIM(company_name)) = ?', [Str::lower(trim($attributes['company_name']))])
            ->where(function ($query) use ($attributes) {
                if (filled($attributes['telephone'] ?? null)) {
                    $query->orWhere('telephone', $attributes['telephone']);
                }

                if (filled($attributes['email'] ?? null)) {
                    $query->orWhere('email', $attributes['email']);
                }
            })
            ->first();
    }

    public function isCompanyNameMapped(): bool
    {
        return in_array('company_name', $this->mapping, true);
    }

    /**
     * Multiple source columns targeting the same real Prospect field is
     * allowed (existing behavior — processMapping() simply keeps whichever
     * one is processed last) but is genuinely ambiguous, so it's surfaced
     * as a non-blocking warning rather than silently changing that
     * behavior. "notes" is exempt since many columns legitimately combine
     * there by design.
     *
     * @return array<int, string> Prospect field labels with more than one column mapped to them
     */
    public function duplicateMappingWarnings(): array
    {
        return collect($this->mapping)
            ->reject(fn (string $target) => in_array($target, ['ignore', 'notes'], true))
            ->countBy()
            ->filter(fn (int $count) => $count > 1)
            ->keys()
            ->map(fn (string $target) => self::TARGET_OPTIONS[$target] ?? $target)
            ->all();
    }

    /**
     * "Apply to all" is a repeatable action: every click sets EVERY row
     * to the chosen value unconditionally, overwriting any prior state —
     * whether that state came from an earlier bulk-apply or an
     * individual per-row click. Previously this only filled in rows
     * with no resolution yet (`! isset($this->duplicateResolutions[$index])`),
     * which meant a second bulk-apply with a different choice found
     * every row already "resolved" from the first click and silently did
     * nothing — confirmed as the root cause via
     * `applyBulkResolution()`'s own `isset()` guard. The admin remains
     * free to override an individual row again AFTER using Apply; this
     * only changes what Apply itself does, not the ability to tweak
     * afterward.
     */
    public function applyBulkResolution(): void
    {
        if (! in_array($this->bulkResolution, ['update', 'new', 'skip'], true)) {
            return;
        }

        foreach (array_keys($this->pendingDuplicates) as $index) {
            $this->duplicateResolutions[$index] = $this->bulkResolution;
        }
    }

    public function completeImport(): void
    {
        foreach ($this->pendingDuplicates as $index => $duplicate) {
            $resolution = $this->duplicateResolutions[$index] ?? null;

            if ($resolution === 'update') {
                DB::transaction(function () use ($duplicate) {
                    $existing = Prospect::find($duplicate['existingId']);

                    if (! $existing) {
                        return;
                    }

                    // Ownership is deliberately left untouched by an
                    // "Update" resolution — this is a data-quality merge
                    // (contact details, notes, etc.), not a reassignment.
                    // The incoming assigned_to is often just the importing
                    // admin's own ID (when the spreadsheet's Assigned Owner
                    // didn't match anyone), which must never silently steal
                    // ownership of an existing Prospect.
                    $updates = collect($duplicate['incoming'])
                        ->except(['created_by', 'assigned_to'])
                        ->filter(fn ($value) => filled($value))
                        ->all();

                    $existing->update($updates);
                });
                $this->summary['updated']++;
            } elseif ($resolution === 'new') {
                DB::transaction(fn () => Prospect::create($duplicate['incoming']));
                $this->summary['addedDespiteDuplicate']++;
            } elseif ($resolution === 'skip') {
                // Skip means do nothing: the existing record is untouched,
                // no new record is created — exactly as if this row had
                // never been in the uploaded file. No DB write at all, so
                // there's nothing to wrap in a transaction here.
                $this->summary['skipped']++;
            }

            // A skipped row's incoming data was never written anywhere, so
            // warnings about that data (e.g. an unmatched Assigned Owner
            // name) would be misleading noise about a write that never
            // happened — suppressed for 'skip' specifically.
            if ($resolution !== 'skip' && ! empty($duplicate['warnings'])) {
                $this->summary['warnings'][] = ['row' => $duplicate['rowNumber'], 'messages' => $duplicate['warnings']];
            }
        }

        $this->step = 'summary';
    }

    public function allDuplicatesResolved(): bool
    {
        foreach (array_keys($this->pendingDuplicates) as $index) {
            if (! isset($this->duplicateResolutions[$index])) {
                return false;
            }
        }

        return true;
    }

    public function startOver(): void
    {
        $this->step = 'upload';
        $this->file = null;
        $this->sheetRows = [];
        $this->headerRowIndex = 0;
        $this->headers = [];
        $this->rows = [];
        $this->mapping = [];
        $this->autoMatchedHeaders = [];
        $this->pendingDuplicates = [];
        $this->duplicateResolutions = [];
        $this->summary = ['total' => 0, 'imported' => 0, 'updated' => 0, 'addedDespiteDuplicate' => 0, 'skipped' => 0, 'failed' => [], 'warnings' => []];
    }
}
