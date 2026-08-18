<?php

namespace Tests\Feature;

use App\Filament\Resources\ExportRequestResource\Pages\ListExportRequests;
use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Filament\Resources\ProposalResource\Pages\ListProposals;
use App\Filament\Resources\ProspectResource\Pages\ListProspects;
use App\Models\User;
use Filament\Facades\Filament;
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
}
