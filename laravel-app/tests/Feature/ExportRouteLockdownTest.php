<?php

namespace Tests\Feature;

use App\Filament\Resources\AppointmentResource\Pages\ListAppointments;
use App\Filament\Resources\FollowUpResource\Pages\ListFollowUps;
use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Filament\Resources\ProposalResource\Pages\ListProposals;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Import Access + Export Approval batch, Section 2.10/2.20: proves the
 * admin-immediate export actions are actually blocked server-side for an
 * employee, not merely hidden. Filament's real callMountedAction() (see
 * vendor/filament/actions/src/Concerns/InteractsWithActions.php) only
 * checks isDisabled(), never isVisible()/isHidden() — a forged Livewire
 * request naming the "exportCsv" action directly (as a browser dev-tools
 * user could send, bypassing the hidden button entirely) would otherwise
 * still execute the closure. Livewire's own ->callAction() test helper
 * pre-asserts visibility before calling, which would hide this gap, so
 * these tests instead drive mountAction()/callMountedAction() directly
 * (skipping that assertion) to faithfully reproduce a forged request. The
 * Halt-based re-check inside every ExportActions closure is what actually
 * stops it — that is what's being exercised here, not the ->visible()
 * flag, which CsvExportTest/FollowUpTabsAndExportTest already cover
 * separately.
 */
class ExportRouteLockdownTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, array{0: class-string}>
     */
    public static function listPages(): array
    {
        return [
            [ListLeads::class],
            [ListAppointments::class],
            [ListProposals::class],
            [ListFollowUps::class],
        ];
    }

    #[DataProvider('listPages')]
    public function test_employee_forging_a_request_for_the_immediate_export_action_is_blocked(string $page): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        $test = Livewire::test($page)
            ->mountAction('exportCsv')
            ->setActionData(['stage' => null, 'scope' => 'pending'])
            ->callMountedAction();

        $test->assertNoFileDownloaded();
    }

    #[DataProvider('listPages')]
    public function test_admin_calling_the_immediate_export_action_still_works(string $page): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $test = Livewire::test($page)
            ->mountAction('exportCsv')
            ->setActionData(['stage' => null, 'scope' => 'pending'])
            ->callMountedAction();

        $test->assertFileDownloaded();
    }

    #[DataProvider('listPages')]
    public function test_admin_forging_a_request_for_the_request_export_action_is_blocked(string $page): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test($page)
            ->mountAction('requestExport')
            ->setActionData(['stage' => null, 'scope' => 'pending'])
            ->callMountedAction();

        $this->assertDatabaseCount('export_requests', 0);
    }
}
