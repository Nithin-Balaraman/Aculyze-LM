<?php

namespace Tests\Feature;

use App\Filament\Resources\ProspectResource;
use App\Filament\Resources\ProspectResource\Pages\ListProspects;
use App\Models\Prospect;
use App\Models\ProspectTableColumnPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Covers three related Prospects-table "Toggle columns" items (see this
 * task's own numbering):
 *
 * #3 completeness — designation/mobile/website/state/pincode/source were
 * genuine gaps (real, non-computed Prospect columns, used on both the
 * create/edit form and ViewProspect's own infolist, with no design reason
 * found to exclude them) and are now toggleable, alongside the 7 that
 * already were. gstin/billing_address/billing_state and the creator
 * relation were assessed and NOT added — both are absent from
 * ViewProspect's infolist too, and the billing fields' own migration
 * docblock scopes them specifically to Proposal-creation, not general
 * company-directory browsing.
 *
 * #4 persistence — DB-backed via ProspectTableColumnPreference, one row
 * per user, read/written from ListProspects (see that class's own
 * docblocks for exactly why a lazily-cached auth()->user()->relation
 * property was rejected in favor of a direct query).
 *
 * #5 auto-filters — every toggleable column now has a matching filter
 * that is visible only while its column is shown, and hiding a column
 * clears that filter's value outright (not just the control).
 */
class ProspectTableColumnToggleTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    /** #3: every genuinely-added column is toggleable, alongside the pre-existing ones. */
    public function test_every_expected_column_is_toggleable(): void
    {
        $this->actor();

        $columns = Livewire::test(ListProspects::class)->instance()->getTable()->getColumns();

        $toggleable = collect($columns)->filter(fn ($column) => $column->isToggleable())->map(fn ($column) => $column->getName())->all();

        foreach ([
            'contact_person', 'email', 'industry', 'city', 'address', 'locality', 'created_at',
            'designation', 'mobile', 'website', 'state', 'pincode', 'source',
        ] as $expected) {
            $this->assertContains($expected, $toggleable, "Expected '{$expected}' to be toggleable.");
        }

        // company_name (the title column) and Assigned To are deliberately always-visible, not toggleable.
        $this->assertNotContains('company_name', $toggleable);
    }

    /**
     * ProspectResource::TOGGLEABLE_COLUMNS is a second, independent list of
     * the same column names (needed because ListProspects::
     * bootedInteractsWithTable() must compute a default toggle state
     * before $this->getTable() can be called at all — see that method's
     * own docblock). Guards the two lists from drifting apart.
     */
    public function test_toggleable_columns_constant_matches_the_actual_toggleable_columns(): void
    {
        $this->actor();

        $columns = Livewire::test(ListProspects::class)->instance()->getTable()->getColumns();
        $actual = collect($columns)->filter(fn ($column) => $column->isToggleable())->map(fn ($column) => $column->getName())->sort()->values()->all();

        $this->assertSame($actual, collect(ProspectResource::TOGGLEABLE_COLUMNS)->sort()->values()->all());
    }

    /** #4: toggle state persists for the SAME user across a real logout/login cycle. */
    public function test_toggle_state_persists_across_logout_and_login(): void
    {
        $user = $this->actor();

        Livewire::test(ListProspects::class)->set('toggledTableColumns.city', true);

        $this->assertTrue(
            ProspectTableColumnPreference::query()->where('user_id', $user->id)->first()->toggled_columns['city'],
        );

        auth()->logout();
        $this->actingAs($user->fresh());

        $restored = Livewire::test(ListProspects::class)->get('toggledTableColumns');

        $this->assertTrue($restored['city']);
    }

    /** #4: toggle state is independent per user — User A's change never leaks into User B's view. */
    public function test_toggle_state_is_independent_per_user(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->actingAs($userA);
        Livewire::test(ListProspects::class)->set('toggledTableColumns.city', true);

        $this->actingAs($userB->fresh());
        $userBState = Livewire::test(ListProspects::class)->get('toggledTableColumns');

        $this->assertFalse($userBState['city']);
        $this->assertNull(ProspectTableColumnPreference::query()->where('user_id', $userB->id)->first());
    }

    /** #5: a toggleable column's filter is hidden while the column itself is hidden (the default state). */
    public function test_filter_is_hidden_while_its_column_is_toggled_off(): void
    {
        $this->actor();

        $test = Livewire::test(ListProspects::class);
        $cityFilter = $this->findFilter($test, 'city');

        $this->assertFalse($cityFilter->isVisible());
    }

    /** #5: toggling a column on makes its filter appear. */
    public function test_filter_appears_when_its_column_is_toggled_on(): void
    {
        $this->actor();

        $test = Livewire::test(ListProspects::class);
        $test->set('toggledTableColumns.city', true);

        $cityFilter = $this->findFilter($test, 'city');

        $this->assertTrue($cityFilter->isVisible());
    }

    /**
     * #5, the full round trip this task explicitly asked for: set a filter
     * value, toggle that column off, confirm the filter's effect on
     * results is gone — not merely hidden behind an invisible control.
     */
    public function test_toggling_a_column_off_clears_its_filter_value_and_effect(): void
    {
        $user = $this->actor();
        $chennai = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id, 'city' => 'Chennai']);
        $coimbatore = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id, 'city' => 'Coimbatore']);

        $test = Livewire::test(ListProspects::class);
        $test->set('toggledTableColumns.city', true);
        $test->set('tableFilters.city.value', 'Chennai');

        $test->assertCanSeeTableRecords([$chennai])->assertCanNotSeeTableRecords([$coimbatore]);

        $test->set('toggledTableColumns.city', false);

        $this->assertArrayNotHasKey('city', $test->get('tableFilters'));
        $test->assertCanSeeTableRecords([$chennai, $coimbatore]);

        $cityFilter = $this->findFilter($test, 'city');
        $this->assertFalse($cityFilter->isVisible());
    }

    /** The pre-existing 'industry' filter now follows toggle state exactly like the newly-added ones. */
    public function test_pre_existing_industry_filter_now_follows_toggle_state_too(): void
    {
        $this->actor();

        $test = Livewire::test(ListProspects::class);
        $this->assertFalse($this->findFilter($test, 'industry')->isVisible());

        $test->set('toggledTableColumns.industry', true);
        $this->assertTrue($this->findFilter($test, 'industry')->isVisible());
    }

    /** The 'assigned_to' filter is fixed/always-available (admin-only), unrelated to any toggle state. */
    public function test_assigned_to_filter_is_unaffected_by_toggle_state(): void
    {
        $admin = User::factory()->create(['role' => \App\Enums\UserRole::Admin]);
        $this->actingAs($admin);

        $test = Livewire::test(ListProspects::class);

        $this->assertTrue($this->findFilter($test, 'assigned_to')->isVisible());

        $test->set('toggledTableColumns.city', true);
        $test->set('toggledTableColumns.city', false);

        $this->assertTrue($this->findFilter($test, 'assigned_to')->isVisible());
    }

    private function findFilter(\Livewire\Features\SupportTesting\Testable $test, string $name): ?\Filament\Tables\Filters\BaseFilter
    {
        foreach ($test->instance()->getTable()->getFilters(withHidden: true) as $filter) {
            if ($filter->getName() === $name) {
                return $filter;
            }
        }

        return null;
    }
}
