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
            ->call('confirmHeaderRow')
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
            ->call('confirmHeaderRow')
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
            ->call('confirmHeaderRow')
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
            ->call('confirmHeaderRow')
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
            ->call('confirmHeaderRow')
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
            ->call('confirmHeaderRow')
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
            ->call('confirmHeaderRow')
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
            ->call('confirmHeaderRow')
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
            ->call('confirmHeaderRow')
            ->call('processMapping')
            ->assertSet('step', 'duplicates');

        $this->assertFalse($test->instance()->allDuplicatesResolved());

        $test->set('bulkResolution', 'update')
            ->call('applyBulkResolution');

        $this->assertTrue($test->instance()->allDuplicatesResolved());
    }

    // --- "Apply to all" bug fix + Skip option batch. The bug: bulk-apply
    // correctly updated duplicateResolutions server-side all along (see
    // allDuplicatesResolved() assertions above, which already proved
    // that) — the actual defect was that the per-row radio inputs never
    // rendered an explicit checked attribute for ANY value, so when
    // applyBulkResolution() changed the value via a DIFFERENT Livewire
    // action than the radio's own change event, the re-rendered HTML was
    // byte-identical to before and Livewire's morph had nothing to diff,
    // so the browser's real checked state silently never updated. Fixed
    // by deriving @checked() directly from server state on every render.
    // These tests assert against the actual rendered HTML's checked
    // attribute, not just the underlying array, since that's exactly
    // what the bug hid and a plain assertSet() on the array wouldn't
    // have caught. ---

    /**
     * @return array<int, string> the resolution values ('update'/'new'/
     * 'skip'/null) whose radio is actually rendered `checked` for each
     * pending-duplicate index, in order — read straight from the raw
     * HTML so a regression of the @checked() fix would fail this test.
     */
    private function checkedResolutionsFromHtml(string $html, int $count): array
    {
        $result = [];

        for ($i = 0; $i < $count; $i++) {
            $result[$i] = null;

            foreach (['update', 'new', 'skip'] as $value) {
                $pattern = '/wire:model\.live="duplicateResolutions\.'.$i.'"\s+value="'.$value.'"\s+checked/';

                if (preg_match($pattern, $html)) {
                    $result[$i] = $value;
                }
            }
        }

        return $result;
    }

    public function test_bulk_apply_update_visibly_checks_every_unresolved_row(): void
    {
        $admin = User::factory()->admin()->create();
        Prospect::factory()->create(['company_name' => 'Sunrise Plastics', 'telephone' => '+91 98765 43210']);
        Prospect::factory()->create(['company_name' => 'Coastal Foods', 'telephone' => '+91 91111 22222']);
        Prospect::factory()->create(['company_name' => 'Northern Textiles', 'telephone' => '+91 93333 44444']);
        $this->actingAs($admin);

        $test = Livewire::test(ImportProspects::class)
            ->set('file', $this->buildXlsx([
                $this->legacyRow(),
                $this->legacyRow(['Company Name' => 'Coastal Foods', 'Contact Number' => '+91 91111 22222']),
                $this->legacyRow(['Company Name' => 'Northern Textiles', 'Contact Number' => '+91 93333 44444']),
            ]))
            ->call('processUpload')
            ->call('confirmHeaderRow')
            ->call('processMapping')
            ->assertSet('step', 'duplicates');

        $this->assertSame([null, null, null], $this->checkedResolutionsFromHtml($test->html(), 3));

        $test->set('bulkResolution', 'update')->call('applyBulkResolution');

        $this->assertSame(['update', 'update', 'update'], $this->checkedResolutionsFromHtml($test->html(), 3));
    }

    public function test_bulk_apply_add_new_visibly_checks_every_unresolved_row(): void
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
            ->call('confirmHeaderRow')
            ->call('processMapping')
            ->assertSet('step', 'duplicates');

        $test->set('bulkResolution', 'new')->call('applyBulkResolution');

        $this->assertSame(['new', 'new'], $this->checkedResolutionsFromHtml($test->html(), 2));
    }

    public function test_bulk_apply_skip_visibly_checks_every_unresolved_row(): void
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
            ->call('confirmHeaderRow')
            ->call('processMapping')
            ->assertSet('step', 'duplicates');

        $test->set('bulkResolution', 'skip')->call('applyBulkResolution');

        $this->assertSame(['skip', 'skip'], $this->checkedResolutionsFromHtml($test->html(), 2));
    }

    /**
     * A bulk-apply sets a default for every UNRESOLVED row — an
     * individual row must still be changeable afterward for a one-off
     * exception, and that change must itself visibly check correctly.
     */
    public function test_individual_row_can_override_a_bulk_applied_choice_afterward(): void
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
            ->call('confirmHeaderRow')
            ->call('processMapping')
            ->assertSet('step', 'duplicates');

        $test->set('bulkResolution', 'update')->call('applyBulkResolution');
        $this->assertSame(['update', 'update'], $this->checkedResolutionsFromHtml($test->html(), 2));

        // Override row 1 individually to Skip instead.
        $test->set('duplicateResolutions.1', 'skip');
        $this->assertSame(['update', 'skip'], $this->checkedResolutionsFromHtml($test->html(), 2));
        $this->assertSame('update', $test->get('duplicateResolutions')[0]);
    }

    /**
     * A bulk-apply must never overwrite a row the admin already resolved
     * individually — the existing "only fill in unresolved rows"
     * behavior, unaffected by this batch, re-verified alongside it.
     */
    public function test_bulk_apply_does_not_override_an_already_resolved_row(): void
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
            ->call('confirmHeaderRow')
            ->call('processMapping')
            ->assertSet('step', 'duplicates');

        $test->set('duplicateResolutions.0', 'skip');
        $test->set('bulkResolution', 'update')->call('applyBulkResolution');

        $this->assertSame(['skip', 'update'], $this->checkedResolutionsFromHtml($test->html(), 2));
    }

    /**
     * The locked decision: Skip means do nothing. No partial write, no
     * orphaned record — the existing record must be byte-for-byte
     * unchanged and no new Prospect created for that row.
     */
    public function test_skip_leaves_the_existing_record_completely_untouched_and_creates_nothing(): void
    {
        $admin = User::factory()->admin()->create();
        $existing = Prospect::factory()->create([
            'company_name' => 'Sunrise Plastics',
            'telephone' => '+91 98765 43210',
            'contact_person' => 'Original Contact',
            'email' => 'original@sunriseplastics.test',
        ]);
        $originalUpdatedAt = $existing->updated_at;
        $this->actingAs($admin);

        Livewire::test(ImportProspects::class)
            ->set('file', $this->buildXlsx([$this->legacyRow()]))
            ->call('processUpload')
            ->call('confirmHeaderRow')
            ->call('processMapping')
            ->assertSet('step', 'duplicates')
            ->set('duplicateResolutions.0', 'skip')
            ->call('completeImport')
            ->assertSet('step', 'summary')
            ->assertSet('summary.skipped', 1)
            ->assertSet('summary.updated', 0)
            ->assertSet('summary.addedDespiteDuplicate', 0);

        // Exactly one Prospect (the pre-existing one) — nothing new added.
        $this->assertSame(1, Prospect::count());

        $existing->refresh();
        $this->assertSame('Original Contact', $existing->contact_person);
        $this->assertSame('original@sunriseplastics.test', $existing->email);
        $this->assertTrue($originalUpdatedAt->equalTo($existing->updated_at));
    }

    /**
     * The completion summary must correctly count all three outcomes
     * independently when a batch mixes them.
     */
    public function test_completion_summary_correctly_counts_all_three_outcomes(): void
    {
        $admin = User::factory()->admin()->create();
        Prospect::factory()->create(['company_name' => 'Sunrise Plastics', 'telephone' => '+91 98765 43210']);
        Prospect::factory()->create(['company_name' => 'Coastal Foods', 'telephone' => '+91 91111 22222']);
        Prospect::factory()->create(['company_name' => 'Northern Textiles', 'telephone' => '+91 93333 44444']);
        $this->actingAs($admin);

        $test = Livewire::test(ImportProspects::class)
            ->set('file', $this->buildXlsx([
                $this->legacyRow(),
                $this->legacyRow(['Company Name' => 'Coastal Foods', 'Contact Number' => '+91 91111 22222']),
                $this->legacyRow(['Company Name' => 'Northern Textiles', 'Contact Number' => '+91 93333 44444']),
            ]))
            ->call('processUpload')
            ->call('confirmHeaderRow')
            ->call('processMapping')
            ->assertSet('step', 'duplicates');

        $test->set('duplicateResolutions.0', 'update')
            ->set('duplicateResolutions.1', 'new')
            ->set('duplicateResolutions.2', 'skip')
            ->call('completeImport')
            ->assertSet('step', 'summary')
            ->assertSet('summary.updated', 1)
            ->assertSet('summary.addedDespiteDuplicate', 1)
            ->assertSet('summary.skipped', 1);

        // 3 pre-existing + 1 genuinely new (the "add as new anyway" row).
        $this->assertSame(4, Prospect::count());
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
            ->call('confirmHeaderRow')
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
            ->call('confirmHeaderRow')
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
            ->call('confirmHeaderRow')
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
            ->call('confirmHeaderRow')
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
            ->call('confirmHeaderRow')
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
            ->call('processUpload')
            ->call('confirmHeaderRow');

        $this->assertTrue($test->instance()->isCompanyNameMapped());

        $test->set('mapping.Company Name', 'ignore');
        $this->assertFalse($test->instance()->isCompanyNameMapped());
    }

    // --- Field-mapping bug fix batch: invisible-whitespace normalization,
    // label-vs-legacy-dictionary mismatch, and the header-row confirmation
    // step. See the investigation report for the two confirmed root
    // causes this batch fixes. ---

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     */
    private function buildCustomXlsx(array $headers, array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray($rows, null, 'A2');

        $tmpPath = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);
        $contents = file_get_contents($tmpPath);
        unlink($tmpPath);

        return UploadedFile::fake()->createWithContent('custom-sheet.xlsx', $contents);
    }

    /**
     * The confirmed root cause: PHP's own trim() only strips the classic
     * ASCII whitespace set, not Unicode "separator" characters like
     * U+00A0 (non-breaking space) — a common Word/web copy-paste
     * artifact. A header that LOOKS identical to "Company Name" but
     * carries a trailing NBSP must still auto-map correctly now.
     */
    public function test_header_with_trailing_non_breaking_space_still_auto_maps(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(ImportProspects::class)
            ->set('file', $this->buildCustomXlsx(["Company Name\u{00A0}"], [['Acme Corp']]))
            ->call('processUpload')
            ->call('confirmHeaderRow')
            ->assertSet('step', 'mapping')
            ->assertSet("mapping.Company Name\u{00A0}", 'company_name');
    }

    /**
     * Control: a genuinely clean, exact-match header must keep working
     * exactly as before (proves the NBSP fix doesn't regress the plain
     * case it's layered on top of).
     */
    public function test_clean_exact_match_header_still_auto_maps(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(ImportProspects::class)
            ->set('file', $this->buildCustomXlsx(['Company Name'], [['Acme Corp']]))
            ->call('processUpload')
            ->call('confirmHeaderRow')
            ->assertSet('mapping.Company Name', 'company_name');
    }

    /**
     * The second confirmed root cause: the old FIELD_SUGGESTIONS
     * dictionary was keyed by the LEGACY sheet's own historical column
     * phrasings ("Contact Number", "Mobile Number", "Email Address",
     * "Full Location", "Source Sheet"), not this page's own current
     * TARGET_OPTIONS labels ("Telephone", "Mobile", "Email", "Address",
     * "Source"). A user typing the label they see in this very page's own
     * dropdown got silently defaulted to Notes. All five must now
     * auto-map to their real field.
     */
    public function test_current_ui_labels_typed_as_headers_now_auto_map_correctly(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $headers = ['Company Name', 'Telephone', 'Mobile', 'Email', 'Address', 'Source'];
        $row = ['Acme Corp', '+91 90000 00001', '+91 90000 00002', 'acme@example.test', '123 Example Street', 'Referral'];

        $test = Livewire::test(ImportProspects::class)
            ->set('file', $this->buildCustomXlsx($headers, [$row]))
            ->call('processUpload')
            ->call('confirmHeaderRow')
            ->assertSet('step', 'mapping');

        $test->assertSet('mapping.Company Name', 'company_name')
            ->assertSet('mapping.Telephone', 'telephone')
            ->assertSet('mapping.Mobile', 'mobile')
            ->assertSet('mapping.Email', 'email')
            ->assertSet('mapping.Address', 'address')
            ->assertSet('mapping.Source', 'source');

        // Confirm it isn't just mapping-preview correctness — the values
        // actually reach their Prospect attributes end to end.
        $test->call('processMapping')->assertSet('summary.imported', 1);

        $prospect = Prospect::sole();
        $this->assertSame('+91 90000 00001', $prospect->telephone);
        $this->assertSame('+91 90000 00002', $prospect->mobile);
        $this->assertSame('acme@example.test', $prospect->email);
        $this->assertSame('123 Example Street', $prospect->address);
        $this->assertSame('Referral', $prospect->source);
    }

    /**
     * The legacy sheet's own historical phrasing must still auto-map too
     * — the fix adds recognition, it doesn't replace one dictionary with
     * another equally narrow one.
     */
    public function test_legacy_sheet_phrasing_still_auto_maps_alongside_current_labels(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $test = Livewire::test(ImportProspects::class)
            ->set('file', $this->buildXlsx([$this->legacyRow()]))
            ->call('processUpload')
            ->call('confirmHeaderRow');

        $test->assertSet('mapping.Contact Number', 'telephone')
            ->assertSet('mapping.Mobile Number', 'mobile')
            ->assertSet('mapping.Email Address', 'email')
            ->assertSet('mapping.Full Location', 'address')
            ->assertSet('mapping.Source Sheet', 'source');
    }

    /**
     * Case-insensitivity was already working before this fix and must
     * keep working — separator (space/dash/underscore) and case variants
     * of the same field should all resolve identically.
     */
    public function test_case_and_separator_variants_all_resolve_to_the_same_field(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $test = Livewire::test(ImportProspects::class)
            ->set('file', $this->buildCustomXlsx(
                ['company name', 'COMPANY NAME', 'Company_Name', 'company-name'],
                [['A', 'B', 'C', 'D']],
            ))
            ->call('processUpload')
            ->call('confirmHeaderRow');

        $test->assertSet('mapping.company name', 'company_name')
            ->assertSet('mapping.COMPANY NAME', 'company_name')
            ->assertSet('mapping.Company_Name', 'company_name')
            ->assertSet('mapping.company-name', 'company_name');
    }

    /**
     * The confirmed header-row-detection gap: the previous implementation
     * always treated physical row 1 as headers unconditionally, with no
     * detection or confirmation — silently misreading an instructional
     * row above the real headers. The new header-row confirmation step
     * must show a genuine choice and let the admin pick correctly.
     */
    public function test_header_row_confirmation_step_lets_the_admin_correct_a_misdetected_first_row(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['Please fill in the fields below'], null, 'A1');
        $sheet->fromArray(['Company Name', 'Telephone'], null, 'A2');
        $sheet->fromArray(['Acme Corp', '+91 90000 00003'], null, 'A3');
        $tmpPath = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);
        $contents = file_get_contents($tmpPath);
        unlink($tmpPath);
        $file = UploadedFile::fake()->createWithContent('instructional-row.xlsx', $contents);

        $test = Livewire::test(ImportProspects::class)
            ->set('file', $file)
            ->call('processUpload')
            ->assertSet('step', 'header-row')
            ->assertSet('headerRowIndex', 0);

        // Confirming row 0 (the instructional row) as-is would be wrong —
        // it has only one real cell (PhpSpreadsheet pads shorter rows with
        // null out to the sheet's widest row) and no real headers.
        $preview = $test->instance()->previewRows();
        $this->assertSame(['Please fill in the fields below', null], $preview[0]);
        $this->assertSame(['Company Name', 'Telephone'], $preview[1]);

        $test->set('headerRowIndex', 1)
            ->call('confirmHeaderRow')
            ->assertSet('step', 'mapping')
            ->assertSet('headers', ['Company Name', 'Telephone'])
            ->assertSet('mapping.Company Name', 'company_name')
            ->assertSet('mapping.Telephone', 'telephone');

        $test->call('processMapping')->assertSet('summary.imported', 1);

        $prospect = Prospect::sole();
        $this->assertSame('Acme Corp', $prospect->company_name);
        $this->assertSame('+91 90000 00003', $prospect->telephone);
    }

    /**
     * Confirming row 0 unchanged (the default) must still work exactly as
     * before for a normal, well-formed workbook — the new step adds a
     * confirmation, not a mandatory extra click burden beyond that.
     */
    public function test_header_row_confirmation_defaults_to_row_one_for_a_normal_workbook(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(ImportProspects::class)
            ->set('file', $this->buildXlsx([$this->legacyRow()]))
            ->call('processUpload')
            ->assertSet('step', 'header-row')
            ->assertSet('headerRowIndex', 0)
            ->call('confirmHeaderRow')
            ->assertSet('step', 'mapping')
            ->assertSet('mapping.Company Name', 'company_name');
    }

    // --- Import Access + Export Approval batch: employee import ownership ---

    public function test_employee_import_creates_prospects_owned_by_that_employee(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        Livewire::test(ImportProspects::class)
            ->set('file', $this->buildXlsx([$this->legacyRow(['Assigned Owner' => ''])]))
            ->call('processUpload')
            ->call('confirmHeaderRow')
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
            ->call('confirmHeaderRow')
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
            ->call('confirmHeaderRow')
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
            ->call('confirmHeaderRow')
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
            ->call('confirmHeaderRow')
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
            ->call('confirmHeaderRow')
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
            ->call('confirmHeaderRow')
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
            ->call('confirmHeaderRow')
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
            ->call('confirmHeaderRow')
            ->call('processMapping')
            ->assertSet('summary.imported', 1);

        $prospect = Prospect::sole();
        $prospect->delete();

        Livewire::test(ListProspects::class)
            ->assertCanNotSeeTableRecords([$prospect]);
    }
}
