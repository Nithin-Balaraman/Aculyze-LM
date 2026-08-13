<?php

namespace Tests\Feature;

use App\Enums\ExportableResource;
use App\Enums\ExportRequestStatus;
use App\Filament\Pages\MyExportRequests;
use App\Filament\Resources\AppointmentResource\Pages\ListAppointments;
use App\Filament\Resources\FollowUpResource\Pages\ListFollowUps;
use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Filament\Resources\ProposalResource\Pages\ListProposals;
use App\Models\ExportRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * One-Time Approved Export Download batch, Section 2: an Approved
 * ExportRequest authorizes exactly one successful CSV download. downloaded_at
 * already existed on ExportRequest (previously written but never actually
 * consulted for eligibility) — reused here as the one-time consumed marker,
 * so no migration was needed. See ExportRequest::isDownloadable()/
 * claimForDownload() and MyExportRequests's download action.
 */
class OneTimeExportDownloadTest extends TestCase
{
    use RefreshDatabase;

    private function makeApprovedRequest(User $employee, array $filters = ['stage' => null]): ExportRequest
    {
        $request = ExportRequest::create([
            'user_id' => $employee->id,
            'resource' => ExportableResource::Lead,
            'filters' => $filters,
            'status' => ExportRequestStatus::Pending,
        ]);
        $request->approve(User::factory()->admin()->create());

        return $request;
    }

    public function test_first_successful_download_records_the_downloaded_state(): void
    {
        $employee = User::factory()->create();
        $request = $this->makeApprovedRequest($employee);
        $this->actingAs($employee);

        $this->assertNull($request->downloaded_at);

        Livewire::test(MyExportRequests::class)->callTableAction('download', $request);

        $request->refresh();
        $this->assertNotNull($request->downloaded_at);
        $this->assertSame(ExportRequestStatus::Approved, $request->status);
    }

    public function test_the_same_request_cannot_be_downloaded_a_second_time(): void
    {
        $employee = User::factory()->create();
        $request = $this->makeApprovedRequest($employee);
        $this->actingAs($employee);

        Livewire::test(MyExportRequests::class)->callTableAction('download', $request);

        // The Download action itself is no longer offered — proven directly
        // (not just via a forged replay, see the test below) since this is
        // exactly what "cannot be downloaded a second time" means at the
        // point the employee would actually try.
        Livewire::test(MyExportRequests::class)
            ->assertTableActionHidden('download', $request->fresh());
        $this->assertFalse($employee->can('download', $request->fresh()));
    }

    public function test_direct_route_replay_after_download_is_rejected(): void
    {
        $employee = User::factory()->create();
        $request = $this->makeApprovedRequest($employee);
        $this->actingAs($employee);

        Livewire::test(MyExportRequests::class)->callTableAction('download', $request);

        // Forges a second attempt by driving mountAction/callMountedAction
        // directly (bypassing the test helper's own visibility pre-check —
        // see ExportRouteLockdownTest for why this is the faithful way to
        // simulate a replayed/direct request against a hidden action).
        $test = Livewire::test(MyExportRequests::class)
            ->mountTableAction('download', $request->fresh()->id)
            ->callMountedTableAction();

        $test->assertNoFileDownloaded();
        $this->assertFalse($employee->can('download', $request->fresh()));
    }

    public function test_refreshing_the_page_does_not_restore_download_eligibility(): void
    {
        $employee = User::factory()->create();
        $request = $this->makeApprovedRequest($employee);
        $this->actingAs($employee);

        Livewire::test(MyExportRequests::class)->callTableAction('download', $request);

        // A brand-new component mount, simulating a page refresh — pulls
        // fresh from the database rather than reusing any in-memory state.
        Livewire::test(MyExportRequests::class)
            ->assertTableActionHidden('download', $request->fresh());
    }

    public function test_two_competing_claim_attempts_cannot_both_succeed(): void
    {
        $employee = User::factory()->create();
        $request = $this->makeApprovedRequest($employee);

        // Two independent in-memory copies of the same row, standing in for
        // two nearly-simultaneous HTTP requests that both loaded the record
        // before either had committed a write.
        $first = ExportRequest::find($request->id);
        $second = ExportRequest::find($request->id);

        $this->assertTrue($first->claimForDownload());
        $this->assertFalse($second->claimForDownload());

        $this->assertSame(1, ExportRequest::whereNotNull('downloaded_at')->count());
    }

    public function test_downloaded_approved_request_does_not_prevent_a_new_identical_request(): void
    {
        $employee = User::factory()->create();
        $request = $this->makeApprovedRequest($employee, ['stage' => null]);
        $this->actingAs($employee);

        Livewire::test(MyExportRequests::class)->callTableAction('download', $request);

        Livewire::test(ListLeads::class)->callAction('requestExport', data: ['stage' => null]);

        $this->assertDatabaseCount('export_requests', 2);
        $newRequest = ExportRequest::where('id', '!=', $request->id)->sole();
        $this->assertSame(ExportRequestStatus::Pending, $newRequest->status);
    }

    public function test_undownloaded_approved_request_still_does_not_prevent_a_new_identical_request(): void
    {
        $employee = User::factory()->create();
        $this->makeApprovedRequest($employee, ['stage' => null]);
        $this->actingAs($employee);

        Livewire::test(ListLeads::class)->callAction('requestExport', data: ['stage' => null]);

        $this->assertDatabaseCount('export_requests', 2);
    }

    public function test_identical_pending_request_is_still_blocked(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        Livewire::test(ListLeads::class)->callAction('requestExport', data: ['stage' => null]);
        Livewire::test(ListLeads::class)->callAction('requestExport', data: ['stage' => null]);

        $this->assertDatabaseCount('export_requests', 1);
    }

    public function test_a_newly_approved_replacement_request_can_itself_be_downloaded_exactly_once(): void
    {
        $employee = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $original = $this->makeApprovedRequest($employee, ['stage' => null]);
        $this->actingAs($employee);
        Livewire::test(MyExportRequests::class)->callTableAction('download', $original);

        Livewire::test(ListLeads::class)->callAction('requestExport', data: ['stage' => null]);
        $replacement = ExportRequest::where('id', '!=', $original->id)->sole();
        $replacement->approve($admin);

        Livewire::test(MyExportRequests::class)
            ->callTableAction('download', $replacement->fresh())
            ->assertFileDownloaded();

        $this->assertNotNull($replacement->fresh()->downloaded_at);

        // And the replacement itself is now spent too.
        Livewire::test(MyExportRequests::class)
            ->assertTableActionHidden('download', $replacement->fresh());
    }

    public function test_expired_approved_request_still_cannot_be_downloaded(): void
    {
        $employee = User::factory()->create();
        $request = $this->makeApprovedRequest($employee);
        $request->forceFill(['expires_at' => now()->subDay()])->save();
        $this->actingAs($employee);

        Livewire::test(MyExportRequests::class)
            ->assertTableActionHidden('download', $request);

        $this->assertNull($request->fresh()->downloaded_at);
    }

    /**
     * @return array<int, array{0: class-string, 1: string}>
     */
    public static function adminImmediateExportPages(): array
    {
        return [
            [ListLeads::class, 'stage'],
            [ListAppointments::class, 'stage'],
            [ListProposals::class, 'stage'],
            [ListFollowUps::class, 'scope'],
        ];
    }

    #[DataProvider('adminImmediateExportPages')]
    public function test_admin_direct_export_remains_repeatable_and_untouched_by_export_requests(string $page, string $criteriaKey): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $data = $criteriaKey === 'scope' ? ['scope' => 'pending'] : ['stage' => null];

        Livewire::test($page)->callAction('exportCsv', data: $data)->assertFileDownloaded();
        Livewire::test($page)->callAction('exportCsv', data: $data)->assertFileDownloaded();

        $this->assertDatabaseCount('export_requests', 0);
    }
}
