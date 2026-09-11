<?php

namespace Tests\Feature;

use App\Enums\ProposalVersionLifecycle;
use App\Enums\UserRole;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use App\Models\Prospect;
use App\Models\User;
use App\Services\ProposalReleaseService;
use App\Services\ProposalSendAttachmentArchiver;
use App\Services\ProposalVersionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 4A-3.3, Sections L/M/V (66-69): no public route for a Release/Send/
 * attachment archive, never the public disk, and every archived path is
 * server-generated (content-addressed by SHA-256), never user-controlled.
 */
class ProposalSendSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config(['aculyze.organization_identity' => [
            'legal_name' => 'Aculyze Solutions Pvt Ltd',
            'registered_address' => '123 Business Park, Bengaluru',
            'gstin' => '29ABCDE1234F1Z5',
        ]]);
    }

    public function test_no_public_release_or_send_route_exists(): void
    {
        $suspiciousNames = collect(Route::getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter()
            ->filter(fn (string $name) => str_contains($name, 'proposal')
                && (str_contains($name, 'release') || str_contains($name, 'send') || str_contains($name, 'attachment')));

        $this->assertCount(0, $suspiciousNames, 'A named route referencing Proposal Release/Send/attachment archives exists — this must only ever be reachable through an authenticated, policy-gated Filament action, never a direct route.');
    }

    public function test_send_attachment_archive_is_never_on_the_public_disk(): void
    {
        $archiver = app(ProposalSendAttachmentArchiver::class);
        $result = $archiver->archive(1, 'SOME-BYTES');

        $this->assertFalse(Storage::disk('public')->exists($result['archivedPath']));
        $this->assertTrue(Storage::disk('local')->exists($result['archivedPath']));
    }

    public function test_send_attachment_archive_path_is_content_addressed_server_generated(): void
    {
        $archiver = app(ProposalSendAttachmentArchiver::class);
        $result = $archiver->archive(42, 'SOME-BYTES');

        $this->assertMatchesRegularExpression(
            '#^proposal-send-attachments/42/[0-9a-f]{64}$#',
            $result['archivedPath']
        );
        $this->assertSame(hash('sha256', 'SOME-BYTES'), $result['checksum']);
    }

    public function test_path_traversal_is_impossible_in_the_archived_path(): void
    {
        $archiver = app(ProposalSendAttachmentArchiver::class);
        $result = $archiver->archive(1, 'SOME-BYTES');

        $this->assertStringNotContainsString('..', $result['archivedPath']);
        $this->assertStringNotContainsString('/./', $result['archivedPath']);
    }

    // --- 74: PHASE4_OUTCOME_CUTOVER_GATE remains OPEN, untouched ---

    public function test_phase4_outcome_cutover_gate_remains_open_and_untouched_by_send(): void
    {
        $seniorManager = User::factory()->admin()->create();
        $manager = User::factory()->create(['role' => UserRole::Manager, 'manager_id' => $seniorManager->id]);
        $employee = User::factory()->create(['role' => UserRole::Employee, 'manager_id' => $manager->id]);

        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $lead = Lead::create([
            'prospect_id' => $prospect->id, 'assigned_to' => $employee->id, 'created_by' => $employee->id,
            'stage' => 'validated', 'temperature' => 'hot', 'notes' => 'Fixture.',
        ]);
        $proposal = Proposal::create([
            'lead_id' => $lead->id, 'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id, 'created_by' => $employee->id, 'stage' => 'being_prepared',
        ]);
        $version = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'lifecycle_status' => ProposalVersionLifecycle::Draft,
            'customer_name_snapshot' => 'Acme Corp',
        ]);
        ProposalVersionLine::create([
            'proposal_version_id' => $version->id, 'line_number' => 1,
            'item_name' => 'Widget', 'quantity' => 1, 'unit_price' => 100,
        ]);
        $proposal->forceFill(['current_version_id' => $version->id])->save();

        app(ProposalVersionWorkflowService::class)->submit($version->fresh(), $manager);
        app(ProposalVersionWorkflowService::class)->approve($version->fresh(), $seniorManager);
        app(ProposalReleaseService::class)->release($version->fresh(), $manager);

        $this->assertFileExists(base_path('docs/PHASE4_OUTCOME_CUTOVER_GATE.md'));
        $gate = file_get_contents(base_path('docs/PHASE4_OUTCOME_CUTOVER_GATE.md'));
        $this->assertStringContainsStringIgnoringCase('open', $gate);
    }
}
