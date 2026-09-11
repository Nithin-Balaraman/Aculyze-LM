<?php

namespace Tests\Feature;

use App\Enums\ProposalVersionLifecycle;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use App\Models\Prospect;
use App\Models\User;
use App\Services\ProposalPdfArtifactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 4A-3.2, Sections G/O/V (locked Decision 22): no public Proposal PDF
 * route, no reliance on the public disk or storage:link, and every stored
 * path is server-generated (a UUID), never user-controlled.
 */
class ProposalPdfArtifactSecurityTest extends TestCase
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

    private function approvedVersion(User $employee): ProposalVersion
    {
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
        $version->forceFill(['lifecycle_status' => ProposalVersionLifecycle::Approved])->save();
        $proposal->forceFill(['current_version_id' => $version->id])->save();

        return $version->fresh(['proposal']);
    }

    public function test_no_public_proposal_pdf_route_exists(): void
    {
        $suspiciousNames = collect(Route::getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter()
            ->filter(fn (string $name) => str_contains($name, 'proposal') && (str_contains($name, 'pdf') || str_contains($name, 'artifact')));

        $this->assertCount(0, $suspiciousNames, 'A named route referencing Proposal PDFs/artifacts exists — this must only ever be reachable through an authenticated, policy-gated Filament action, never a direct route.');
    }

    public function test_pdf_is_stored_on_the_private_local_disk_never_public(): void
    {
        $employee = User::factory()->create();
        $version = $this->approvedVersion($employee);

        $artifact = app(ProposalPdfArtifactService::class)->generateIfMissing($version, $employee);

        $this->assertFalse(Storage::disk('public')->exists($artifact->storage_path));
        $this->assertTrue(Storage::disk('local')->exists($artifact->storage_path));
    }

    public function test_storage_path_is_server_generated_not_derived_from_any_user_input(): void
    {
        $employee = User::factory()->create();
        $version = $this->approvedVersion($employee);

        $artifact = app(ProposalPdfArtifactService::class)->generateIfMissing($version, $employee);

        // Deterministic prefix (organization/proposal/version ids) plus a
        // server-generated UUID filename — nothing here is ever built from
        // a filename, header, or form field the client supplied.
        $this->assertMatchesRegularExpression(
            '#^proposal-pdfs/\d+/\d+/\d+/[0-9a-f-]{36}\.pdf$#',
            $artifact->storage_path
        );
        $this->assertStringNotContainsString('..', $artifact->storage_path);
    }

    public function test_no_storage_link_symlink_is_required_for_this_feature(): void
    {
        // The 'local' disk's own config has no 'links' entry pointing at
        // it (see config/filesystems.php's 'links' array, which only maps
        // the UNRELATED public disk) — final PDFs are reachable only via
        // Storage::disk('local')->download(), never a symlinked public
        // path.
        $links = config('filesystems.links', []);

        foreach ($links as $target) {
            $this->assertStringNotContainsString('private', $target);
        }
    }
}
