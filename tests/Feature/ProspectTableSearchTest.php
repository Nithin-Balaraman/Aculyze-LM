<?php

namespace Tests\Feature;

use App\Filament\Resources\ProspectResource\Pages\ListProspects;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Supersedes the prior investigation's fix (a persistent description
 * explaining an 8-column substring search — see git history). That
 * explained a search that has since been deliberately replaced: the
 * Prospects table search is now Company Name only, prefix match — like
 * an address book/contacts list. See ProspectResource::table()'s own
 * comment on the company_name column for the exact mechanism
 * (->searchable(query: ...), which fully replaces Filament's own default
 * LIKE '%term%' column-search branch — confirmed directly against
 * Filament\Tables\Columns\Concerns\InteractsWithTableQuery::
 * applySearchConstraint()).
 *
 * The other 7 previously-searchable columns (contact_person, telephone,
 * email, industry, city, address, locality) no longer participate in
 * this search box at all. The top-nav global search is a separate
 * mechanism (ProspectResource::$recordTitleAttribute = 'company_name')
 * and was already company_name-only, so it is unaffected either way.
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

    public function test_prefix_match_finds_company_starting_with_the_term(): void
    {
        $owner = $this->owner();
        $aculyze = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id, 'company_name' => 'Aculyze Solutions LLP']);
        $other = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id, 'company_name' => 'Precision Engineering Co']);

        Livewire::test(ListProspects::class)
            ->set('tableSearch', 'Ac')
            ->assertCanSeeTableRecords([$aculyze])
            ->assertCanNotSeeTableRecords([$other]);
    }

    /**
     * The core behavior change: a term that appears mid-name (not as a
     * prefix) must NOT match — this is the difference between the old
     * substring search and the new address-book-style prefix search.
     */
    public function test_term_appearing_mid_name_does_not_match(): void
    {
        $owner = $this->owner();
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id, 'company_name' => 'Precision Engineering Co']);

        // "cision" is a genuine substring of "Precision" but not a prefix.
        Livewire::test(ListProspects::class)
            ->set('tableSearch', 'cision')
            ->assertCanNotSeeTableRecords([$prospect]);
    }

    /**
     * The exact originally-reported scenario: a term that only matches
     * other (now non-searchable) columns must return nothing, not the
     * old cross-column matches.
     */
    public function test_search_no_longer_matches_other_columns(): void
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

    /** The superseded description text must not still be presented as current behavior. */
    public function test_table_no_longer_carries_the_superseded_search_scope_description(): void
    {
        $this->owner();

        $description = Livewire::test(ListProspects::class)->instance()->getTable()->getDescription();

        $this->assertNull($description);
    }
}
