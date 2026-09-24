<?php

namespace Tests\Feature;

use App\Filament\Resources\ProspectResource\Pages\ListProspects;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Default sort for the Prospects table (no manual sort/search active) is
 * now "most recently updated first" (updated_at desc), replacing the
 * previous created_at desc — see ProspectResource::table()'s own comment
 * on ->defaultSort(). A freshly created row's updated_at equals its
 * created_at at the moment of creation, so "most recently added" still
 * lands at the top exactly as before; the difference only shows once an
 * older row is edited, which now correctly jumps it back to the top too.
 */
class ProspectTableDefaultSortTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        return $owner;
    }

    public function test_freshly_created_prospect_appears_at_the_top(): void
    {
        $owner = $this->owner();
        $older = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id, 'created_at' => now()->subDays(5), 'updated_at' => now()->subDays(5)]);

        $fresh = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);

        $records = Livewire::test(ListProspects::class)->instance()->getTable()->getRecords();

        $this->assertSame($fresh->id, $records->first()->id);
        $this->assertSame([$fresh->id, $older->id], $records->pluck('id')->all());
    }

    /** Proves updated_at, not just created_at, drives the order. */
    public function test_editing_an_older_prospect_moves_it_back_to_the_top(): void
    {
        $owner = $this->owner();
        $older = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id, 'created_at' => now()->subDays(5), 'updated_at' => now()->subDays(5)]);
        $newer = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id, 'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3)]);

        // Before any edit: newer (by creation) is first, as expected.
        $recordsBefore = Livewire::test(ListProspects::class)->instance()->getTable()->getRecords();
        $this->assertSame([$newer->id, $older->id], $recordsBefore->pluck('id')->all());

        // Edit the OLDER prospect — its created_at stays the oldest, but
        // touching it now makes it the most RECENTLY updated.
        $older->update(['notes' => 'Called back, following up next week.']);

        $recordsAfter = Livewire::test(ListProspects::class)->instance()->getTable()->getRecords();
        $this->assertSame([$older->id, $newer->id], $recordsAfter->pluck('id')->all());
    }

    public function test_manual_column_sort_still_overrides_the_default(): void
    {
        $owner = $this->owner();
        $zebra = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id, 'company_name' => 'Zebra Co', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $apple = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id, 'company_name' => 'Apple Co']);

        // Default (no manual sort): most recently updated (Apple, created after Zebra) first.
        $default = Livewire::test(ListProspects::class)->instance()->getTable()->getRecords();
        $this->assertSame([$apple->id, $zebra->id], $default->pluck('id')->all());

        // Manual sort by Company Name ascending overrides it entirely.
        $test = Livewire::test(ListProspects::class);
        $test->call('sortTable', 'company_name', 'asc');
        $sorted = $test->instance()->getTable()->getRecords();
        $this->assertSame([$apple->id, $zebra->id], $sorted->pluck('id')->all());

        $sql = $test->instance()->getFilteredSortedTableQuery()->toSql();
        $this->assertStringContainsString('order by `company_name` asc', $sql);
    }

    /** The search-ranking interaction re-confirmed with the new default sort as tie-breaker (see commit 2c4a8ff). */
    public function test_search_ranking_stays_primary_with_updated_at_as_tiebreaker(): void
    {
        $owner = $this->owner();
        $containsOnly = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id, 'company_name' => 'Acme Corp Industries', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $startsWith = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id, 'company_name' => 'Corp Solutions Ltd', 'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3)]);

        $test = Livewire::test(ListProspects::class)->set('tableSearch', 'corp');
        $records = $test->instance()->getTable()->getRecords();

        // Starts-with ranks first regardless of updated_at recency.
        $this->assertSame([$startsWith->id, $containsOnly->id], $records->pluck('id')->all());

        $sql = $test->instance()->getFilteredSortedTableQuery()->toSql();
        $this->assertStringContainsString("case when company_name like ? then 0 else 1 end, `updated_at` desc", $sql);
    }
}
