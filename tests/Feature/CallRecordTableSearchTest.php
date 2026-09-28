<?php

namespace Tests\Feature;

use App\Filament\Resources\CallRecordResource\Pages\ListCallRecords;
use App\Models\CallRecord;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Calls Phase 2, item 1: same blended search ranking as Prospects
 * (ProspectResource::table(), commit 2c4a8ff) — starts-with matches
 * ranked before contains-elsewhere matches, in one result set, with the
 * table's own sort (defaultSort('called_at', 'desc'), or whatever a user
 * has actively clicked) surviving as the tie-breaker.
 *
 * The WHERE clause is unchanged (Company is searched via the
 * `prospect.company_name` relationship column's own default
 * ->searchable(), a plain `LIKE '%term%'` via whereRelation() — see
 * vendor/filament/tables/src/Columns/Concerns/InteractsWithTableQuery::
 * applySearchConstraint()). The ranking itself is a ->modifyQueryUsing()
 * scalar correlated subquery on prospects (correlated on
 * call_records.prospect_id = prospects.id, since company_name has no
 * column on call_records itself, unlike Prospects' own direct-column
 * version) — NOT inside the column's own ->searchable() closure, for the
 * exact same reason as Prospects: Filament invokes that closure from
 * inside a ->where(function ($query) {...}) nested group, and Laravel's
 * Query\Builder::whereNested()/forNestedWhere() build that group with a
 * separate, throwaway Builder whose ->orders never gets copied back onto
 * the real query.
 */
class CallRecordTableSearchTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        return $admin;
    }

    private function makeCall(User $admin, string $companyName, ?string $calledAt = null): CallRecord
    {
        $prospect = Prospect::factory()->create([
            'assigned_to' => $admin->id,
            'created_by' => $admin->id,
            'company_name' => $companyName,
        ]);

        return CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $admin->id,
            'called_at' => $calledAt ?? now(),
            'outcome' => 'no_answer',
        ]);
    }

    /**
     * The task's own concrete example, adapted for Calls: "corp" must
     * return BOTH "Corp Solutions Ltd" (starts with) and "Acme Corp
     * Industries" (contains, not prefix) — starts-with ranked first.
     * Asserts the actual ORDER of getRecords(), not just presence.
     */
    public function test_starts_with_match_ranks_before_contains_only_match(): void
    {
        $admin = $this->admin();
        $containsOnly = $this->makeCall($admin, 'Acme Corp Industries');
        $startsWith = $this->makeCall($admin, 'Corp Solutions Ltd');

        $records = Livewire::test(ListCallRecords::class)
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

    /** A mid-name-only substring (not a prefix) must still match. */
    public function test_term_appearing_only_mid_name_still_matches(): void
    {
        $admin = $this->admin();
        $call = $this->makeCall($admin, 'Precision Engineering Co');

        Livewire::test(ListCallRecords::class)
            ->set('tableSearch', 'cision')
            ->assertCanSeeTableRecords([$call]);
    }

    public function test_term_not_present_anywhere_in_name_does_not_match(): void
    {
        $admin = $this->admin();
        $call = $this->makeCall($admin, 'Precision Engineering Co');

        Livewire::test(ListCallRecords::class)
            ->set('tableSearch', 'zephyr')
            ->assertCanNotSeeTableRecords([$call]);
    }

    public function test_search_is_case_insensitive(): void
    {
        $admin = $this->admin();
        $call = $this->makeCall($admin, 'Aculyze Solutions LLP');

        foreach (['ac', 'AC', 'Ac', 'aC'] as $term) {
            Livewire::test(ListCallRecords::class)
                ->set('tableSearch', $term)
                ->assertCanSeeTableRecords([$call]);
        }
    }

    public function test_empty_search_shows_everything(): void
    {
        $admin = $this->admin();
        $calls = collect(range(1, 5))->map(fn (int $i) => $this->makeCall($admin, "Company {$i}"));

        Livewire::test(ListCallRecords::class)
            ->set('tableSearch', '')
            ->assertCanSeeTableRecords($calls);
    }

    /** Ranking must not appear in the SQL at all when there is no search term — defaultSort is untouched. */
    public function test_ranking_is_a_no_op_when_search_is_empty(): void
    {
        $this->admin();

        $sql = Livewire::test(ListCallRecords::class)
            ->instance()
            ->getFilteredSortedTableQuery()
            ->toSql();

        $this->assertStringNotContainsString('case when', $sql);
        $this->assertStringContainsString('order by `called_at` desc', $sql);
    }

    /** Ranking must combine with, not replace, an actively user-applied column sort. */
    public function test_ranking_stays_primary_alongside_a_user_applied_sort(): void
    {
        $admin = $this->admin();
        $alpha = $this->makeCall($admin, 'Corp Alpha Group');
        $solutions = $this->makeCall($admin, 'Corp Solutions Ltd');
        $acmeCorp = $this->makeCall($admin, 'Acme Corp Industries');

        $test = Livewire::test(ListCallRecords::class)->set('tableSearch', 'corp');
        $test->call('sortTable', 'prospect.company_name', 'asc');

        $records = $test->instance()->getTable()->getRecords();

        $this->assertSame([$alpha->id, $solutions->id, $acmeCorp->id], $records->pluck('id')->all());
    }

    /** The other searchable/sortable columns must be unaffected by this change. */
    public function test_other_columns_still_sort_and_filter_normally(): void
    {
        $admin = $this->admin();
        $older = $this->makeCall($admin, 'Alpha Co', now()->subDays(2));
        $newer = $this->makeCall($admin, 'Beta Co', now()->subDay());

        $records = Livewire::test(ListCallRecords::class)
            ->instance()
            ->getTable()
            ->getRecords();

        $this->assertSame([$newer->id, $older->id], $records->pluck('id')->all());
    }
}
