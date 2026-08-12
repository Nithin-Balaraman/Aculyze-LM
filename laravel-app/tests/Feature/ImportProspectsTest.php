<?php

namespace Tests\Feature;

use App\Filament\Pages\ImportProspects;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Change Request "Decision 5": the legacy-spreadsheet -> Prospect import
 * wizard. Exercises the exact column set given in the decision, admin-only
 * access, the required-Company-Name validation, notes consolidation for
 * columns with no direct Prospect field home, Assigned Owner name matching,
 * and interactive per-row duplicate resolution (update vs. add-as-new).
 */
class ImportProspectsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Livewire forces file uploads made in tests onto its own
        // 'tmp-for-tests' disk (FileUploadConfiguration::disk()), regardless
        // of the app's configured default filesystem disk. ImportProspects
        // reads the uploaded file back via Storage::path() against the
        // default disk, which is correct in production (where both resolve
        // to the same disk) but would miss the file here without pointing
        // the default disk at the same physical root Livewire actually used.
        config(['filesystems.disks.local.root' => storage_path('framework/testing/disks/tmp-for-tests')]);
    }

    private const LEGACY_HEADERS = [
        'Lead ID', 'Company Name', 'Industry', 'City', 'Full Location',
        'Contact Number', 'Contact Person', 'Email Address', 'Mobile Number',
        'Website', 'Source Sheet', 'Lead Status', 'Priority',
        'Latest Call Outcome', 'Latest Remarks', 'Next Action',
        'Follow-up Date', 'Last Activity Date', 'Assigned Owner',
        'Action Status', 'Data Quality Note',
    ];

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    private function buildXlsx(array $rows, array $headers = self::LEGACY_HEADERS): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray($rows, null, 'A2');

        $tmpPath = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);
        $contents = file_get_contents($tmpPath);
        unlink($tmpPath);

        return UploadedFile::fake()->createWithContent('legacy-sheet.xlsx', $contents);
    }

    private function legacyRow(array $overrides = []): array
    {
        $defaults = [
            'Lead ID' => 'L-1001',
            'Company Name' => 'Sunrise Plastics',
            'Industry' => 'Manufacturing',
            'City' => 'Coimbatore',
            'Full Location' => 'SIDCO Estate, Coimbatore',
            'Contact Number' => '+91 98765 43210',
            'Contact Person' => 'Ravi Kumar',
            'Email Address' => 'ravi@sunriseplastics.test',
            'Mobile Number' => '+91 90000 11111',
            'Website' => 'sunriseplastics.test',
            'Source Sheet' => 'Trade Fair 2024',
            'Lead Status' => 'Warm',
            'Priority' => 'High',
            'Latest Call Outcome' => 'Requirement Identified',
            'Latest Remarks' => 'Interested in bulk order.',
            'Next Action' => 'Send proposal',
            'Follow-up Date' => '2026-01-10',
            'Last Activity Date' => '2026-01-01',
            'Assigned Owner' => 'Ilaya Bharathi',
            'Action Status' => 'Open',
            'Data Quality Note' => 'Phone unverified',
        ];

        $row = array_merge($defaults, $overrides);

        return array_map(fn (string $header) => $row[$header], self::LEGACY_HEADERS);
    }

    public function test_import_page_is_admin_only(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->get('/admin/import-prospects')->assertForbidden();
    }

    public function test_non_xlsx_file_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(ImportProspects::class)
            ->set('file', UploadedFile::fake()->create('legacy-sheet.csv', 10))
            ->call('processUpload')
            ->assertSet('step', 'upload')
            ->assertSet('uploadError', 'Only .xlsx files are supported.');
    }

    public function test_upload_parses_headers_and_suggests_a_mapping(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(ImportProspects::class)
            ->set('file', $this->buildXlsx([$this->legacyRow()]))
            ->call('processUpload')
            ->assertSet('step', 'mapping')
            ->assertSet('mapping.Company Name', 'company_name')
            ->assertSet('mapping.Contact Number', 'phone_primary')
            ->assertSet('mapping.Assigned Owner', 'assigned_owner')
            // "Lead Status" has no direct Prospect field home per Decision 5
            // and must fall back to being folded into Notes.
            ->assertSet('mapping.Lead Status', 'notes');
    }

    public function test_mapping_requires_company_name(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(ImportProspects::class)
            ->set('file', $this->buildXlsx([$this->legacyRow()]))
            ->call('processUpload')
            ->set('mapping.Company Name', 'ignore')
            ->call('processMapping')
            ->assertSet('step', 'mapping')
            ->assertSet('uploadError', 'Map at least one column to Company Name before continuing — it is required.');

        $this->assertSame(0, Prospect::count());
    }

    public function test_row_missing_company_name_value_is_reported_as_failed(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(ImportProspects::class)
            ->set('file', $this->buildXlsx([$this->legacyRow(['Company Name' => ''])]))
            ->call('processUpload')
            ->call('processMapping')
            ->assertSet('step', 'summary')
            ->assertSet('summary.imported', 0);

        $this->assertSame(0, Prospect::count());
    }

    public function test_new_row_is_imported_with_unmapped_columns_consolidated_into_notes(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create(['name' => 'Ilaya Bharathi']);
        $this->actingAs($admin);

        Livewire::test(ImportProspects::class)
            ->set('file', $this->buildXlsx([$this->legacyRow()]))
            ->call('processUpload')
            ->call('processMapping')
            ->assertSet('step', 'summary')
            ->assertSet('summary.imported', 1);

        $prospect = Prospect::sole();
        $this->assertSame('Sunrise Plastics', $prospect->company_name);
        $this->assertSame('Ravi Kumar', $prospect->contact_person);
        $this->assertSame('+91 98765 43210', $prospect->phone_primary);
        $this->assertSame('ravi@sunriseplastics.test', $prospect->email);
        $this->assertSame($owner->id, $prospect->assigned_to);

        // "Lead ID", "Lead Status", "Priority", "Latest Call Outcome",
        // "Latest Remarks", "Next Action", "Follow-up Date",
        // "Last Activity Date", "Action Status", "Data Quality Note" have no
        // direct Prospect field per Decision 5 and must land in Notes as a
        // labeled block rather than being silently dropped.
        $this->assertStringContainsString('Lead Status: Warm', $prospect->notes);
        $this->assertStringContainsString('Latest Call Outcome: Requirement Identified', $prospect->notes);
        $this->assertStringContainsString('Data Quality Note: Phone unverified', $prospect->notes);
    }

    public function test_assigned_owner_not_matching_any_employee_defaults_to_importing_admin_with_a_warning(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(ImportProspects::class)
            ->set('file', $this->buildXlsx([$this->legacyRow(['Assigned Owner' => 'Nobody Real'])]))
            ->call('processUpload')
            ->call('processMapping')
            ->assertSet('summary.imported', 1);

        $prospect = Prospect::sole();
        $this->assertSame($admin->id, $prospect->assigned_to);
    }

    public function test_matching_row_is_queued_as_a_pending_duplicate_instead_of_silently_skipped_or_created(): void
    {
        $admin = User::factory()->admin()->create();
        $existing = Prospect::factory()->create([
            'company_name' => 'Sunrise Plastics',
            'phone_primary' => '+91 98765 43210',
            'email' => 'original@sunriseplastics.test',
        ]);
        $this->actingAs($admin);

        Livewire::test(ImportProspects::class)
            ->set('file', $this->buildXlsx([$this->legacyRow()]))
            ->call('processUpload')
            ->call('processMapping')
            ->assertSet('step', 'duplicates')
            ->assertSet('pendingDuplicates.0.existingId', $existing->id);

        // Not created and not modified yet — awaiting the admin's choice.
        $this->assertSame(1, Prospect::count());
        $this->assertSame('original@sunriseplastics.test', $existing->fresh()->email);
    }

    public function test_duplicate_resolved_as_update_merges_data_but_never_touches_existing_ownership(): void
    {
        $admin = User::factory()->admin()->create();
        $originalOwner = User::factory()->create();
        $existing = Prospect::factory()->create([
            'company_name' => 'Sunrise Plastics',
            'phone_primary' => '+91 98765 43210',
            'assigned_to' => $originalOwner->id,
            'email' => null,
        ]);
        $this->actingAs($admin);

        // "Assigned Owner" in the sheet won't match any real employee here,
        // so the incoming row's assigned_to would just be the importing
        // admin — which must never silently steal ownership on an update.
        Livewire::test(ImportProspects::class)
            ->set('file', $this->buildXlsx([$this->legacyRow(['Assigned Owner' => 'Unmatched Name'])]))
            ->call('processUpload')
            ->call('processMapping')
            ->assertSet('step', 'duplicates')
            ->set('duplicateResolutions.0', 'update')
            ->call('completeImport')
            ->assertSet('step', 'summary')
            ->assertSet('summary.updated', 1);

        $existing->refresh();
        $this->assertSame('ravi@sunriseplastics.test', $existing->email);
        $this->assertSame($originalOwner->id, $existing->assigned_to);
        $this->assertSame(1, Prospect::count());
    }

    public function test_duplicate_resolved_as_new_adds_a_second_record(): void
    {
        $admin = User::factory()->admin()->create();
        Prospect::factory()->create([
            'company_name' => 'Sunrise Plastics',
            'phone_primary' => '+91 98765 43210',
        ]);
        $this->actingAs($admin);

        Livewire::test(ImportProspects::class)
            ->set('file', $this->buildXlsx([$this->legacyRow()]))
            ->call('processUpload')
            ->call('processMapping')
            ->set('duplicateResolutions.0', 'new')
            ->call('completeImport')
            ->assertSet('summary.addedDespiteDuplicate', 1);

        $this->assertSame(2, Prospect::count());
    }

    public function test_finish_import_is_blocked_until_every_duplicate_is_resolved(): void
    {
        $admin = User::factory()->admin()->create();
        Prospect::factory()->create(['company_name' => 'Sunrise Plastics', 'phone_primary' => '+91 98765 43210']);
        Prospect::factory()->create(['company_name' => 'Coastal Foods', 'phone_primary' => '+91 91111 22222']);
        $this->actingAs($admin);

        $test = Livewire::test(ImportProspects::class)
            ->set('file', $this->buildXlsx([
                $this->legacyRow(),
                $this->legacyRow(['Company Name' => 'Coastal Foods', 'Contact Number' => '+91 91111 22222']),
            ]))
            ->call('processUpload')
            ->call('processMapping')
            ->assertSet('step', 'duplicates');

        $this->assertFalse($test->instance()->allDuplicatesResolved());

        $test->set('bulkResolution', 'update')
            ->call('applyBulkResolution');

        $this->assertTrue($test->instance()->allDuplicatesResolved());
    }
}
