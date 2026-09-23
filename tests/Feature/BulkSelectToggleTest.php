<?php

namespace Tests\Feature;

use App\Filament\Resources\ExportRequestResource\Pages\ListExportRequests;
use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Filament\Resources\ProposalResource\Pages\ListProposals;
use App\Filament\Resources\ProspectResource\Pages\ListProspects;
use App\Models\Prospect;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Tables\Actions\ActionGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * UI fix: bulk-select checkboxes sat permanently on the left of every row,
 * pushing the row-actions dropdown off-screen. They're now hidden by
 * default (see the overridden checkbox.blade.php under
 * resources/views/vendor/filament-tables/) and only appear once "Select
 * Multiple" is clicked. That toggle button is registered panel-wide in
 * AdminPanelProvider, scoped to the List pages that actually have bulk
 * actions, and gated to admins — mirroring the same condition that
 * already governs whether those bulk actions/checkboxes exist at all.
 *
 * Panel-level render hooks (the toggle button lives in one) are normally
 * registered by Filament\Http\Middleware\SetUpPanel on every real HTTP
 * request. A bare Livewire::test() call never goes through that
 * middleware, so without manually booting the panel first, the hook would
 * never fire for ANYONE regardless of role — silently making every
 * assertDontSee() in this file trivially true. bootAdminPanel() below
 * replicates exactly what that middleware does.
 */
class BulkSelectToggleTest extends TestCase
{
    use RefreshDatabase;

    private function bootAdminPanel(): void
    {
        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);
        Filament::bootCurrentPanel();
    }

    public function test_admin_sees_the_select_multiple_toggle_on_a_resource_with_bulk_actions(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $this->bootAdminPanel();

        Livewire::test(ListProspects::class)
            ->assertSee('Select Multiple');
    }

    public function test_employee_does_not_see_the_select_multiple_toggle(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);
        $this->bootAdminPanel();

        Livewire::test(ListLeads::class)
            ->assertDontSee('Select Multiple');
    }

    public function test_admin_does_not_see_the_select_multiple_toggle_on_a_resource_with_no_bulk_actions(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $this->bootAdminPanel();

        Livewire::test(ListExportRequests::class)
            ->assertDontSee('Select Multiple');
    }

    public function test_proposal_stale_column_is_toggled_hidden_by_default(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $table = Livewire::test(ListProposals::class)->instance()->getTable();

        $this->assertTrue($table->getColumn('is_stale')->isToggledHiddenByDefault());
    }

    /**
     * "Deselect all" moved from a standalone link in the selection-
     * indicator bar into a real bulk action (see App\Support\
     * TableBulkActions::deselectAll()) — a "Bulk actions" dropdown entry on
     * every other resource, but a standalone toolbar button specifically on
     * Prospects (see ProspectResource::table()'s own comment on why). It's
     * a no-op action either way, relying on BulkAction's built-in
     * deselectRecordsAfterCompletion() to actually clear the selection —
     * this test exercises the action itself, not its container, so it's
     * unaffected by that layout difference.
     */
    public function test_admin_can_deselect_all_via_the_bulk_action(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $prospects = Prospect::factory()->count(2)->create();

        Livewire::test(ListProspects::class)
            ->callTableBulkAction('deselectAll', $prospects)
            ->assertSet('selectedTableRecords', []);
    }

    /**
     * Must stay gated identically to the resource's other bulk actions
     * (Delete) — Table::isSelectionEnabled() checks whether ANY bulk
     * action is visible, so an unrestricted "Deselect all" would silently
     * turn row checkboxes back on for Employees, who currently see none.
     */
    public function test_employee_does_not_have_the_deselect_all_bulk_action(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        Livewire::test(ListLeads::class)
            ->assertTableBulkActionHidden('deselectAll');
    }

    /**
     * "Pull Delete/Deselect out of Bulk actions dropdown" (Prospects-only):
     * Table::getBulkActions() returns exactly what ->bulkActions() was
     * given, so a top-level array with no ActionGroup/BulkActionGroup
     * wrapper is precisely what makes Filament render each one as its own
     * standalone button instead of collapsing them behind one dropdown
     * trigger — see ProspectResource::table()'s own comment. This is a
     * layout-only assertion: it says nothing about either action's
     * visibility/confirmation/behavior, which the other tests in this
     * class and DeleteActionsAdminOnlyTest already cover.
     */
    public function test_prospects_bulk_actions_are_standalone_not_grouped_in_a_dropdown(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $bulkActions = Livewire::test(ListProspects::class)
            ->instance()
            ->getTable()
            ->getBulkActions();

        $this->assertCount(2, $bulkActions);

        foreach ($bulkActions as $bulkAction) {
            $this->assertNotInstanceOf(ActionGroup::class, $bulkAction);
        }

        $names = collect($bulkActions)->map(fn ($action) => $action->getName())->all();
        $this->assertEqualsCanonicalizing(['deselectAll', 'delete'], $names);
    }
}
