<?php

namespace Tests\Feature;

use App\Filament\Resources\ProspectResource\Pages\ListProspects;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Supersedes the strict prefix-only behavior from a prior iteration (see
 * git history) — the requirement was refined to a BLENDED search: Company
 * Name only, one ranked result list containing both starts-with and
 * contains-elsewhere matches, starts-with ranked first.
 *
 * The WHERE clause itself is a plain LIKE '%term%' (a prefix match is
 * just a substring match at position 0, so this single clause already
 * covers both cases — see ProspectResource::table()'s company_name
 * column). The ranking is a ->modifyQueryUsing() CASE WHEN ... THEN 0
 * ELSE 1 END order-by on the table's real top-level query — NOT inside
 * the column's own ->searchable(query: ...) closure, which was confirmed
 * (via ->toSql() and inspecting the outer query's ->orders directly, not
 * assumed) to be structurally unable to affect the final ORDER BY:
 * Filament invokes that closure from inside a ->where(function ($query)
 * {...}) group, and Laravel's Query\Builder::whereNested()/
 * forNestedWhere() build that group using a separate, throwaway Builder
 * whose ->orders never gets copied back — only its ->wheres and bindings
 * do (see Illuminate\Database\Query\Builder::addNestedWhereQuery()).
 *
 * The other 7 previously-searchable columns (contact_person, telephone,
 * email, industry, city, address, locality) still don't participate in
 * this search box. The top-nav global search is a separate mechanism
 * (ProspectResource::$recordTitleAttribute = 'company_name') and was
 * already company_name-only, so it is unaffected either way.
 */
class ProspectTableSearchTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        return $owner;
    }

    /**
     * The task's own concrete example: "corp" must return BOTH
     * "Corp Solutions Ltd" (starts with) and "Acme Corp Industries"
     * (contains, not prefix) — starts-with ranked first. This asserts
     * the actual ORDER of getRecords(), not just presence of both rows.
     */
    public function test_starts_with_match_ranks_before_contains_only_match(): void
    {
        $owner = $this->owner();
        $containsOnly = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id, 'company_name' => 'Acme Corp Industries']);
        $startsWith = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id, 'company_name' => 'Corp Solutions Ltd']);

        $records = Livewire::test(ListProspects::class)
            ->set('tableSearch', 'corp')
            ->assertCanSeeTableRecords([$startsWith, $containsOnly])
            ->instance()
            ->getTable()
            ->getRecords();

        $this->assertSame(
            [$startsWith->id, $containsOnly->id],
            $records->pluck('id')->all(),
            'Expected the starts-with match to be ordered before the contains-only match.',
        );
    }

    /** A mid-name-only substring (not a prefix) must still match — this is the "blended", not prefix-only, part. */
    public function test_term_appearing_only_mid_name_still_matches(): void
    {
        $owner = $this->owner();
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id, 'company_name' => 'Precision Engineering Co']);

        // "cision" is a genuine substring of "Precision" but not a prefix.
        Livewire::test(ListProspects::class)
            ->set('tableSearch', 'cision')
            ->assertCanSeeTableRecords([$prospect]);
    }

    /** No match anywhere in the name must still return nothing. */
    public function test_term_not_present_anywhere_in_name_does_not_match(): void
    {
        $owner = $this->owner();
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id, 'company_name' => 'Precision Engineering Co']);

        Livewire::test(ListProspects::class)
            ->set('tableSearch', 'zephyr')
            ->assertCanNotSeeTableRecords([$prospect]);
    }

    /** The originally-reported scenario: a term only present in other (non-searchable) columns must return nothing. */
    public function test_search_does_not_match_other_columns(): void
    {
        $owner = $this->owner();
        $prospect = Prospect::factory()->create([
            'assigned_to' => $owner->id, 'created_by' => $owner->id,
            'company_name' => 'Acme Supplies',
            'industry' => 'Textiles',
            'contact_person' => 'Zara Ibrahim',
            'email' => 'zara@example.com',
            'city' => 'Coimbatore',
            'address' => '218 Antonio Expressway',
            'locality' => 'fort',
            'telephone' => '+91 90000 00099',
        ]);

        foreach (['Textiles', 'Zara', 'example', 'Expressway', 'Coimbatore', 'fort', '90000'] as $term) {
            Livewire::test(ListProspects::class)
                ->set('tableSearch', $term)
                ->assertCanNotSeeTableRecords([$prospect]);
        }
    }

    public function test_search_is_case_insensitive(): void
    {
        $owner = $this->owner();
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id, 'company_name' => 'Aculyze Solutions LLP']);

        foreach (['ac', 'AC', 'Ac', 'aC'] as $term) {
            Livewire::test(ListProspects::class)
                ->set('tableSearch', $term)
                ->assertCanSeeTableRecords([$prospect]);
        }
    }

    public function test_empty_search_shows_everything(): void
    {
        $owner = $this->owner();
        $prospects = Prospect::factory()->count(5)->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);

        Livewire::test(ListProspects::class)
            ->set('tableSearch', '')
            ->assertCanSeeTableRecords($prospects);
    }

    /** Ranking must not appear in the SQL at all when there is no search term — defaultSort is untouched. */
    public function test_ranking_is_a_no_op_when_search_is_empty(): void
    {
        $this->owner();

        $sql = Livewire::test(ListProspects::class)
            ->instance()
            ->getFilteredSortedTableQuery()
            ->toSql();

        $this->assertStringNotContainsString('case when', $sql);
        $this->assertStringContainsString('order by `created_at` desc', $sql);
    }

    /** Ranking must combine with, not replace, an actively user-applied column sort. */
    public function test_ranking_stays_primary_alongside_a_user_applied_sort(): void
    {
        $owner = $this->owner();
        $alpha = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id, 'company_name' => 'Corp Alpha Group']);
        $solutions = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id, 'company_name' => 'Corp Solutions Ltd']);
        $acmeCorp = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id, 'company_name' => 'Acme Corp Industries']);

        $test = Livewire::test(ListProspects::class)->set('tableSearch', 'corp');
        $test->call('sortTable', 'company_name', 'asc');

        $records = $test->instance()->getTable()->getRecords();

        // Both starts-with rows (alphabetical, per the user's sort) come
        // before the contains-only row, which the ranking always keeps last.
        $this->assertSame([$alpha->id, $solutions->id, $acmeCorp->id], $records->pluck('id')->all());
    }

    /** The superseded description text (from an earlier iteration of this fix) must not still be presented. */
    public function test_table_does_not_carry_a_stale_search_scope_description(): void
    {
        $this->owner();

        $description = Livewire::test(ListProspects::class)->instance()->getTable()->getDescription();

        $this->assertNull($description);
    }
}
