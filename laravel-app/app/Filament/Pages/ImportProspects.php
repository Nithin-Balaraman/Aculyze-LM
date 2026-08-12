<?php

namespace App\Filament\Pages;

use App\Models\Prospect;
use App\Models\User;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\WithFileUploads;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Admin-only Excel (.xlsx) -> Prospect (Database) import wizard. Change
 * Request "Decision 5". Built as a plain custom Livewire flow rather than
 * Filament's declarative Form/Wizard components, because the column-
 * mapping step has to be generated dynamically from whatever headers the
 * uploaded file actually has, and the duplicate-resolution step is an
 * interactive per-row review — neither fits a static form schema well.
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
     * Column -> Prospect-field suggestions, matched case-insensitively
     * against the uploaded file's header row. Columns not listed here (or
     * not confidently matched) default to being folded into the Notes
     * catch-all rather than silently dropped.
     *
     * @var array<string, string>
     */
    private const FIELD_SUGGESTIONS = [
        'company name' => 'company_name',
        'contact person' => 'contact_person',
        'contact number' => 'phone_primary',
        'mobile number' => 'phone_secondary',
        'email address' => 'email',
        'website' => 'website',
        'industry' => 'industry',
        'city' => 'city',
        'full location' => 'address',
        'source sheet' => 'source',
        'assigned owner' => 'assigned_owner',
    ];

    /**
     * @var array<string, string>
     */
    public const TARGET_OPTIONS = [
        'ignore' => '— Ignore —',
        'company_name' => 'Company Name (required)',
        'contact_person' => 'Contact Person',
        'phone_primary' => 'Phone (Primary)',
        'phone_secondary' => 'Phone (Secondary)',
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

    /** @var array<int, string> */
    public array $headers = [];

    /** @var array<int, array<string, string>> */
    public array $rows = [];

    /** @var array<string, string> header => target field */
    public array $mapping = [];

    /** @var array<int, array<string, mixed>> */
    public array $pendingDuplicates = [];

    /** @var array<int, string> index => 'update'|'new' */
    public array $duplicateResolutions = [];

    public string $bulkResolution = '';

    /** @var array{total: int, imported: int, updated: int, addedDespiteDuplicate: int, failed: array<int, array{row: int, reason: string}>, warnings: array<int, array{row: int, messages: array<int, string>}>} */
    public array $summary = [
        'total' => 0,
        'imported' => 0,
        'updated' => 0,
        'addedDespiteDuplicate' => 0,
        'failed' => [],
        'warnings' => [],
    ];

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
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

        $headerRow = array_map(fn ($h) => trim((string) $h), array_shift($data));
        $headerRow = array_values(array_filter($headerRow, fn ($h) => $h !== ''));

        if ($headerRow === []) {
            $this->uploadError = 'No column headers were found in the first row.';

            return;
        }

        $dataRows = array_filter($data, fn ($row) => collect($row)->contains(fn ($v) => trim((string) $v) !== ''));

        if ($dataRows === []) {
            $this->uploadError = 'This workbook has headers but no data rows.';

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
            $normalized = Str::lower(trim($header));

            return [$header => self::FIELD_SUGGESTIONS[$normalized] ?? 'notes'];
        })->all();

        $this->step = 'mapping';
    }

    public function backToUpload(): void
    {
        $this->step = 'upload';
        $this->file = null;
        $this->headers = [];
        $this->rows = [];
        $this->mapping = [];
    }

    public function processMapping(): void
    {
        if (! in_array('company_name', $this->mapping, true)) {
            $this->uploadError = 'Map at least one column to Company Name before continuing — it is required.';

            return;
        }

        $this->uploadError = '';
        $this->summary = ['total' => count($this->rows), 'imported' => 0, 'updated' => 0, 'addedDespiteDuplicate' => 0, 'failed' => [], 'warnings' => []];
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

            if ($assignedOwnerRaw) {
                $match = $usersByName->get(Str::lower(trim($assignedOwnerRaw)));

                if ($match) {
                    $assignedTo = $match->id;
                } else {
                    $warnings[] = "Assigned Owner \"{$assignedOwnerRaw}\" did not match any employee — defaulted to you; reassign later if needed.";
                }
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
                        'phone_primary' => $duplicate->phone_primary,
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
        $hasSignal = filled($attributes['phone_primary'] ?? null) || filled($attributes['email'] ?? null);

        if (! $hasSignal) {
            return null;
        }

        return Prospect::query()
            ->whereRaw('LOWER(TRIM(company_name)) = ?', [Str::lower(trim($attributes['company_name']))])
            ->where(function ($query) use ($attributes) {
                if (filled($attributes['phone_primary'] ?? null)) {
                    $query->orWhere('phone_primary', $attributes['phone_primary']);
                }

                if (filled($attributes['email'] ?? null)) {
                    $query->orWhere('email', $attributes['email']);
                }
            })
            ->first();
    }

    public function applyBulkResolution(): void
    {
        if (! in_array($this->bulkResolution, ['update', 'new'], true)) {
            return;
        }

        foreach (array_keys($this->pendingDuplicates) as $index) {
            if (! isset($this->duplicateResolutions[$index])) {
                $this->duplicateResolutions[$index] = $this->bulkResolution;
            }
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
            }

            if (! empty($duplicate['warnings'])) {
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
        $this->headers = [];
        $this->rows = [];
        $this->mapping = [];
        $this->pendingDuplicates = [];
        $this->duplicateResolutions = [];
        $this->summary = ['total' => 0, 'imported' => 0, 'updated' => 0, 'addedDespiteDuplicate' => 0, 'failed' => [], 'warnings' => []];
    }
}
