<?php

namespace Tests\Feature;

use App\Filament\Resources\ProspectResource\Pages\ListProspects;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Investigated as "search for 'x' returns prospects that don't contain
 * 'x' anywhere, while 'z' behaves correctly." The search mechanism itself
 * (Filament's per-column ->searchable() on ProspectResource::table(),
 * a plain WHERE (col LIKE '%term%' OR ...) across company_name,
 * contact_person, telephone, email, industry, city, address, locality —
 * confirmed via ->toSql(), no FULLTEXT index exists on this table at
 * all) is correct and behaves identically for every letter: every
 * returned row genuinely contains the searched letter in at least one
 * searchable column.
 *
 * The reported inconsistency is not a query bug. 6 of the 8 searchable
 * columns are ->toggleable(isToggledHiddenByDefault: true), so only
 * Company Name and Telephone are visible by default. `industry` is one
 * of a small fixed set of values (see ProspectFactory: Textiles,
 * Precision Engineering, Industrial Automation), so a letter that
 * happens to appear in one of those (e.g. the "x" in "Textiles")
 * legitimately matches many rows at once, all via a column that's
 * invisible unless toggled on — which is what made "x" look broken. "z"
 * only appeared to "behave correctly" because its matches happened to be
 * spread across more varied values that session; the same hidden-column
 * mechanism applies to it too (see the genuine-match assertion below).
 *
 * Fix applied: Table::description() (see ProspectResource::table()) — a
 * persistent hint, not ->searchPlaceholder() (which disappears the
 * moment a user types, i.e. exactly when they're looking at confusing
 * results).
 */
class ProspectTableSearchTest extends TestCase
{
    use RefreshDatabase;

    private const SEARCHABLE_COLUMNS = [
        'company_name', 'contact_person', 'telephone', 'email',
        'industry', 'city', 'address', 'locality',
    ];

    private function ownerAndProspects(): User
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        return $owner;
    }

    /**
     * The exact originally-reported scenario: a single "x" match sitting
     * only in a hidden-by-default column (industry) must still be a
     * genuine substring match, not a spurious/unrelated row.
     */
    public function test_x_search_only_returns_rows_genuinely_containing_x(): void
    {
        $owner = $this->ownerAndProspects();

        $matches = Prospect::factory()->create([
            'assigned_to' => $owner->id, 'created_by' => $owner->id,
            'company_name' => 'Ashford Supplies',
            'industry' => 'Textiles',
            'contact_person' => 'Priya Kumar',
            'telephone' => '+91 90000 00001',
            'email' => 'priya@example.com',
            'city' => 'Chennai',
            'address' => '12 Anna Salai',
            'locality' => 'Nungambakkam',
        ]);
        $noMatch = Prospect::factory()->create([
            'assigned_to' => $owner->id, 'created_by' => $owner->id,
            'company_name' => 'Acme Supplies',
            'industry' => 'Precision Engineering',
            'contact_person' => 'Arjun Menon',
            'telephone' => '+91 90000 00002',
            'email' => 'arjun@acme.co.in',
            'city' => 'Chennai',
            'address' => '14 Anna Salai',
            'locality' => 'Adyar',
        ]);

        $test = Livewire::test(ListProspects::class);
        $test->set('tableSearch', 'x');
        $records = $test->instance()->getTable()->getRecords();

        $test->assertCanSeeTableRecords([$matches]);
        $test->assertCanNotSeeTableRecords([$noMatch]);

        foreach ($records as $record) {
            $genuine = false;
            foreach (self::SEARCHABLE_COLUMNS as $column) {
                if (stripos((string) $record->{$column}, 'x') !== false) {
                    $genuine = true;
                    break;
                }
            }
            $this->assertTrue($genuine, "Prospect #{$record->id} matched 'x' search without containing 'x' in any searchable column.");
        }
    }

    /**
     * Control, run the same assertion for several other single-character
     * terms (not just x/z) against real factory-shaped data.
     */
    public function test_single_character_searches_return_only_genuine_matches(): void
    {
        $owner = $this->ownerAndProspects();
        Prospect::factory()->count(15)->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);

        foreach (['a', 'e', 'q', 'j', 'w', 'x', 'z'] as $letter) {
            $test = Livewire::test(ListProspects::class);
            $test->set('tableSearch', $letter);
            $records = $test->instance()->getTable()->getRecords();

            foreach ($records as $record) {
                $genuine = false;
                foreach (self::SEARCHABLE_COLUMNS as $column) {
                    if (stripos((string) $record->{$column}, $letter) !== false) {
                        $genuine = true;
                        break;
                    }
                }
                $this->assertTrue($genuine, "Letter '{$letter}': Prospect #{$record->id} matched without a genuine substring in any searchable column.");
            }
        }
    }

    /** A match can legitimately live only in a hidden-by-default column. */
    public function test_search_matches_a_column_hidden_by_default(): void
    {
        $owner = $this->ownerAndProspects();
        $prospect = Prospect::factory()->create([
            'assigned_to' => $owner->id, 'created_by' => $owner->id,
            'company_name' => 'Acme Corp',
            'contact_person' => 'Zara Ibrahim',
            'telephone' => '+91 90000 00003',
        ]);

        Livewire::test(ListProspects::class)
            ->set('tableSearch', 'zara')
            ->assertCanSeeTableRecords([$prospect]);
    }

    /** The fix: a persistent, always-visible explanation of search scope. */
    public function test_table_has_a_persistent_search_scope_description(): void
    {
        $this->ownerAndProspects();

        $description = Livewire::test(ListProspects::class)->instance()->getTable()->getDescription();

        $this->assertNotNull($description);
        $this->assertStringContainsString('Industry', $description);
    }
}
