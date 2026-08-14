<?php

namespace Tests\Feature;

use App\Filament\Pages\ImportProspects;
use App\Filament\Resources\ProspectResource\Pages\ListProspects;
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

    public function test_import_page_is_available_to_both_admin_and_employee(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create();

        $this->actingAs($admin)->get('/admin/import-prospects')->assertOk();
        $this->actingAs($employee)->get('/admin/import-prospects')->assertOk();
    }

    public function test_import_page_requires_authentication(): void
    {
        $this->get('/admin/import-prospects')->assertRedirect();
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
            ->assertSet('mapping.Contact Number', 'telephone')
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
        $this->assertSame('+91 98765 43210', $prospect->telephone);
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
            'telephone' => '+91 98765 43210',
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
            'telephone' => '+91 98765 43210',
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
            'telephone' => '+91 98765 43210',
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
        Prospect::factory()->create(['company_name' => 'Sunrise Plastics', 'telephone' => '+91 98765 43210']);
        Prospect::factory()->create(['company_name' => 'Coastal Foods', 'telephone' => '+91 91111 22222']);
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

    /**
     * UX Fixes Batch Issue 2: every real mapping destination — not just
     * Company Name/Contact/Phone/Email checked above — must reach its
     * intended Prospect attribute and survive to the saved record. This
     * covers the specific "Location -> Address" case reported as broken.
     */
    public function test_every_mappable_field_persists_to_its_prospect_attribute(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(ImportProspects::class)
            ->set('file', $this->buildXlsx([$this->legacyRow()]))
            ->call('processUpload')
            ->call('processMapping')
            ->assertSet('summary.imported', 1);

        $prospect = Prospect::sole();
        $this->assertSame('Sunrise Plastics', $prospect->company_name);
        $this->assertSame('Manufacturing', $prospect->industry);
        $this->assertSame('Coimbatore', $prospect->city);
        $this->assertSame('SIDCO Estate, Coimbatore', $prospect->address);
        $this->assertSame('Ravi Kumar', $prospect->contact_person);
        $this->assertSame('+91 98765 43210', $prospect->telephone);
        $this->assertSame('+91 90000 11111', $prospect->mobile);
        $this->assertSame('ravi@sunriseplastics.test', $prospect->email);
        $this->assertSame('sunriseplastics.test', $prospect->website);
        $this->assertSame('Trade Fair 2024', $prospect->source);
    }

    /**
     * Same audit as above, but through the duplicate-resolution "Update"
     * path, since that transformation is a separate code path that could
     * independently drop a mapped value.
     */
    public function test_every_mappable_field_persists_through_the_duplicate_update_path(): void
    {
        $admin = User::factory()->admin()->create();
        $existing = Prospect::factory()->create([
            'company_name' => 'Sunrise Plastics',
            'telephone' => '+91 98765 43210',
            'address' => null,
            'city' => null,
            'website' => null,
        ]);
        $this->actingAs($admin);

        Livewire::test(ImportProspects::class)
            ->set('file', $this->buildXlsx([$this->legacyRow()]))
            ->call('processUpload')
            ->call('processMapping')
            ->assertSet('step', 'duplicates')
            ->set('duplicateResolutions.0', 'update')
            ->call('completeImport')
            ->assertSet('summary.updated', 1);

        $existing->refresh();
        $this->assertSame('SIDCO Estate, Coimbatore', $existing->address);
        $this->assertSame('Coimbatore', $existing->city);
        $this->assertSame('sunriseplastics.test', $existing->website);
        $this->assertSame('Manufacturing', $existing->industry);
    }

    /**
     * UX Fixes Batch Issue 1: two source columns sharing an identical
     * header must not collide into a single mapping row — each keeps its
     * own row and its own data reaches the import.
     */
    public function test_duplicate_header_names_are_disambiguated_and_both_columns_import(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['Company Name', 'Notes', 'Notes'], null, 'A1');
        $sheet->fromArray([['Acme Corp', 'First notes column', 'Second notes column']], null, 'A2');
        $tmpPath = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);
        $contents = file_get_contents($tmpPath);
        unlink($tmpPath);
        $file = UploadedFile::fake()->createWithContent('dupe-headers.xlsx', $contents);

        $test = Livewire::test(ImportProspects::class)
            ->set('file', $file)
            ->call('processUpload')
            ->assertSet('step', 'mapping');

        $headers = $test->get('headers');
        $this->assertCount(3, $headers);
        $this->assertSame('Company Name', $headers[0]);
        $this->assertSame('Notes', $headers[1]);
        $this->assertSame('Notes (#2)', $headers[2]);

        $rows = $test->get('rows');
        $this->assertSame('First notes column', $rows[0]['Notes']);
        $this->assertSame('Second notes column', $rows[0]['Notes (#2)']);
    }

    public function test_mapping_more_than_one_column_to_the_same_field_is_flagged_as_a_warning_not_blocked(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['Company Name', 'Contact Number', 'Mobile Number'], null, 'A1');
        $sheet->fromArray([['Acme Corp', '111', '222']], null, 'A2');
        $tmpPath = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);
        $contents = file_get_contents($tmpPath);
        unlink($tmpPath);
        $file = UploadedFile::fake()->createWithContent('same-target.xlsx', $contents);

        $test = Livewire::test(ImportProspects::class)
            ->set('file', $file)
            ->call('processUpload')
            ->assertSet('step', 'mapping');

        $this->assertSame([], $test->instance()->duplicateMappingWarnings());

        // Deliberately point both phone-ish columns at the same destination.
        $test->set('mapping.Mobile Number', 'telephone');

        $this->assertSame(['Telephone'], $test->instance()->duplicateMappingWarnings());

        // Not blocked — the existing "last one wins" behavior is preserved,
        // just surfaced instead of silently changed.
        $test->call('processMapping')->assertSet('step', 'summary');
        $this->assertSame(1, Prospect::count());
    }

    public function test_back_to_mapping_from_duplicates_preserves_the_workbook_and_mapping(): void
    {
        $admin = User::factory()->admin()->create();
        Prospect::factory()->create(['company_name' => 'Sunrise Plastics', 'telephone' => '+91 98765 43210']);
        $this->actingAs($admin);

        $test = Livewire::test(ImportProspects::class)
            ->set('file', $this->buildXlsx([$this->legacyRow()]))
            ->call('processUpload')
            ->call('processMapping')
            ->assertSet('step', 'duplicates');

        $test->call('backToMapping')
            ->assertSet('step', 'mapping')
            ->assertSet('mapping.Company Name', 'company_name')
            ->assertSet('pendingDuplicates', []);

        // The uploaded workbook itself is still intact, so re-processing
        // without changes reaches the duplicate step again.
        $test->call('processMapping')->assertSet('step', 'duplicates');
    }

    public function test_is_company_name_mapped_reflects_current_mapping_state(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $test = Livewire::test(ImportProspects::class)
            ->set('file', $this->buildXlsx([$this->legacyRow()]))
            ->call('processUpload');

        $this->assertTrue($test->instance()->isCompanyNameMapped());

        $test->set('mapping.Company Name', 'ignore');
        $this->assertFalse($test->instance()->isCompanyNameMapped());
    }

    // --- Import Access + Export Approval batch: employee import ownership ---

    public function test_employee_import_creates_prospects_owned_by_that_employee(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        Livewire::test(ImportProspects::class)
            ->set('file', $this->buildXlsx([$this->legacyRow(['Assigned Owner' => ''])]))
            ->call('processUpload')
            ->call('processMapping')
            ->assertSet('summary.imported', 1);

        $prospect = Prospect::sole();
        $this->assertSame($employee->id, $prospect->created_by);
        $this->assertSame($employee->id, $prospect->assigned_to);
    }

    public function test_employee_cannot_use_the_assigned_owner_column_to_hand_ownership_to_someone_else(): void
    {
        $employee = User::factory()->create();
        $otherEmployee = User::factory()->create(['name' => 'Ilaya Bharathi']);
        $this->actingAs($employee);

        Livewire::test(ImportProspects::class)
            ->set('file', $this->buildXlsx([$this->legacyRow(['Assigned Owner' => 'Ilaya Bharathi'])]))
            ->call('processUpload')
            ->call('processMapping')
            ->assertSet('summary.imported', 1);

        $prospect = Prospect::sole();
        // Not $otherEmployee->id, even though the sheet named them explicitly.
        $this->assertSame($employee->id, $prospect->assigned_to);
        $this->assertSame($employee->id, $prospect->created_by);
        $this->assertNotSame($otherEmployee->id, $prospect->assigned_to);
    }

    public function test_admin_import_ownership_behavior_is_unchanged_by_this_batch(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create(['name' => 'Ilaya Bharathi']);
        $this->actingAs($admin);

        Livewire::test(ImportProspects::class)
            ->set('file', $this->buildXlsx([$this->legacyRow(['Assigned Owner' => 'Ilaya Bharathi'])]))
            ->call('processUpload')
            ->call('processMapping')
            ->assertSet('summary.imported', 1);

        $prospect = Prospect::sole();
        // Admin behavior is unchanged: a matched Assigned Owner name still wins.
        $this->assertSame($owner->id, $prospect->assigned_to);
        $this->assertSame($admin->id, $prospect->created_by);
    }

    public function test_employee_duplicate_update_resolution_does_not_transfer_ownership(): void
    {
        $employee = User::factory()->create();
        $originalOwner = User::factory()->create();
        $existing = Prospect::factory()->create([
            'company_name' => 'Sunrise Plastics',
            'telephone' => '+91 98765 43210',
            'assigned_to' => $originalOwner->id,
            'created_by' => $originalOwner->id,
        ]);
        $this->actingAs($employee);

        Livewire::test(ImportProspects::class)
            ->set('file', $this->buildXlsx([$this->legacyRow(['Assigned Owner' => ''])]))
            ->call('processUpload')
            ->call('processMapping')
            ->assertSet('step', 'duplicates')
            ->set('duplicateResolutions.0', 'update')
            ->call('completeImport')
            ->assertSet('summary.updated', 1);

        $existing->refresh();
        $this->assertSame($originalOwner->id, $existing->assigned_to);
        $this->assertSame($originalOwner->id, $existing->created_by);
    }

    public function test_employee_duplicate_detection_finds_prospects_owned_by_other_employees(): void
    {
        $employee = User::factory()->create();
        $otherEmployee = User::factory()->create();
        Prospect::factory()->create([
            'company_name' => 'Sunrise Plastics',
            'telephone' => '+91 98765 43210',
            'assigned_to' => $otherEmployee->id,
            'created_by' => $otherEmployee->id,
        ]);
        $this->actingAs($employee);

        Livewire::test(ImportProspects::class)
            ->set('file', $this->buildXlsx([$this->legacyRow()]))
            ->call('processUpload')
            ->call('processMapping')
            ->assertSet('step', 'duplicates');

        $this->assertSame(1, Prospect::count());
    }

    // --- Employee Prospect Visibility batch: the imported Prospect must be
    // visible through the *real* Filament List page query, not just as raw
    // Prospect model attributes (Section 1.6/1.7). ---

    public function test_employee_a_sees_their_own_imported_prospect_in_the_database_list(): void
    {
        $employeeA = User::factory()->create(['name' => 'Nithin']);
        $this->actingAs($employeeA);

        Livewire::test(ImportProspects::class)
            ->set('file', $this->buildXlsx([$this->legacyRow(['Assigned Owner' => ''])]))
            ->call('processUpload')
            ->call('processMapping')
            ->assertSet('summary.imported', 1);

        $prospect = Prospect::sole();

        Livewire::test(ListProspects::class)
            ->assertCanSeeTableRecords([$prospect]);
    }

    public function test_employee_b_does_not_see_employee_as_imported_prospect_in_the_database_list(): void
    {
        $employeeA = User::factory()->create(['name' => 'Nithin']);
        $employeeB = User::factory()->create(['name' => 'Kural']);
        $this->actingAs($employeeA);

        Livewire::test(ImportProspects::class)
            ->set('file', $this->buildXlsx([$this->legacyRow(['Assigned Owner' => ''])]))
            ->call('processUpload')
            ->call('processMapping')
            ->assertSet('summary.imported', 1);

        $prospect = Prospect::sole();

        $this->actingAs($employeeB);

        Livewire::test(ListProspects::class)
            ->assertCanNotSeeTableRecords([$prospect]);
    }

    public function test_admin_sees_employee_as_imported_prospect_in_the_database_list(): void
    {
        $employeeA = User::factory()->create(['name' => 'Nithin']);
        $admin = User::factory()->admin()->create();
        $this->actingAs($employeeA);

        Livewire::test(ImportProspects::class)
            ->set('file', $this->buildXlsx([$this->legacyRow(['Assigned Owner' => ''])]))
            ->call('processUpload')
            ->call('processMapping')
            ->assertSet('summary.imported', 1);

        $prospect = Prospect::sole();

        $this->actingAs($admin);

        Livewire::test(ListProspects::class)
            ->assertCanSeeTableRecords([$prospect]);
    }

    public function test_existing_manually_created_employee_owned_prospect_remains_visible_in_the_list(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $this->actingAs($employee);

        Livewire::test(ListProspects::class)
            ->assertCanSeeTableRecords([$prospect]);
    }

    public function test_soft_deleted_imported_prospect_is_excluded_from_the_employees_database_list(): void
    {
        $employee = User::factory()->create(['name' => 'Nithin']);
        $this->actingAs($employee);

        Livewire::test(ImportProspects::class)
            ->set('file', $this->buildXlsx([$this->legacyRow(['Assigned Owner' => ''])]))
            ->call('processUpload')
            ->call('processMapping')
            ->assertSet('summary.imported', 1);

        $prospect = Prospect::sole();
        $prospect->delete();

        Livewire::test(ListProspects::class)
            ->assertCanNotSeeTableRecords([$prospect]);
    }
}
