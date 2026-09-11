<?php

namespace Tests\Feature;

use App\Enums\ProposalVersionLifecycle;
use App\Enums\UserRole;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Proposal;
use App\Models\ProposalSend;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use App\Models\Prospect;
use App\Models\User;
use App\Services\ProposalReleaseService;
use App\Services\ProposalSendAttachmentArchiver;
use App\Services\ProposalSendService;
use App\Services\ProposalVersionWorkflowService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 4A-3.3, Sections L/M: the immutable, content-addressed
 * send-attachment archive. The released Proposal PDF is always the
 * primary document — this covers only the OPTIONAL existing-Proposal-
 * attachment manifest, which is a separate concern from the PDF itself.
 */
class ProposalSendAttachmentArchiveTest extends TestCase
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

    /** @return array{employee: User, manager: User, seniorManager: User} */
    private function hierarchy(): array
    {
        $seniorManager = User::factory()->admin()->create();
        $manager = User::factory()->create(['role' => UserRole::Manager, 'manager_id' => $seniorManager->id]);
        $employee = User::factory()->create(['role' => UserRole::Employee, 'manager_id' => $manager->id]);

        return compact('employee', 'manager', 'seniorManager');
    }

    /** @return array{0: Proposal, 1: ProposalVersion} */
    private function releasedProposalWithAttachments(User $employee, User $manager, User $seniorManager, array $attachments): array
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $lead = Lead::create([
            'prospect_id' => $prospect->id, 'assigned_to' => $employee->id, 'created_by' => $employee->id,
            'stage' => 'validated', 'temperature' => 'hot', 'notes' => 'Fixture.',
        ]);

        $attachmentPaths = [];
        $attachmentNames = [];

        foreach ($attachments as $name => $bytes) {
            $path = "proposal-attachments/{$name}";
            Storage::disk('local')->put($path, $bytes);
            $attachmentPaths[] = $path;
            $attachmentNames[$path] = $name;
        }

        $proposal = Proposal::create([
            'lead_id' => $lead->id, 'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id, 'created_by' => $employee->id, 'stage' => 'being_prepared',
            'attachment_paths' => $attachmentPaths,
            'attachment_names' => $attachmentNames,
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

        return [$proposal->fresh(), $version->fresh(['proposal'])];
    }

    private function sendKey(): string
    {
        return (string) \Illuminate\Support\Str::uuid();
    }

    public function test_selected_attachment_is_archived(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        [$proposal, $version] = $this->releasedProposalWithAttachments($employee, $manager, $seniorManager, ['brochure.pdf' => 'PDF-BYTES-1']);
        $path = $proposal->attachment_paths[0];

        $send = app(ProposalSendService::class)->recordManualSend(
            $version, $employee, ['a@b.com'], [], null, null, now(), [$path], $this->sendKey()
        );

        $this->assertSame(1, $send->attachments()->count());
        $manifest = $send->attachments()->first();
        $this->assertTrue(Storage::disk('local')->exists($manifest->archived_path));
    }

    public function test_archived_sha256_is_correct(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        [$proposal, $version] = $this->releasedProposalWithAttachments($employee, $manager, $seniorManager, ['brochure.pdf' => 'PDF-BYTES-1']);
        $path = $proposal->attachment_paths[0];

        $send = app(ProposalSendService::class)->recordManualSend(
            $version, $employee, ['a@b.com'], [], null, null, now(), [$path], $this->sendKey()
        );

        $manifest = $send->attachments()->first();
        $this->assertSame(hash('sha256', 'PDF-BYTES-1'), $manifest->checksum_sha256);
    }

    public function test_archived_byte_size_is_correct(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        [$proposal, $version] = $this->releasedProposalWithAttachments($employee, $manager, $seniorManager, ['brochure.pdf' => 'PDF-BYTES-1']);
        $path = $proposal->attachment_paths[0];

        $send = app(ProposalSendService::class)->recordManualSend(
            $version, $employee, ['a@b.com'], [], null, null, now(), [$path], $this->sendKey()
        );

        $manifest = $send->attachments()->first();
        $this->assertSame(strlen('PDF-BYTES-1'), $manifest->byte_size);
    }

    public function test_original_filename_preserved(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        [$proposal, $version] = $this->releasedProposalWithAttachments($employee, $manager, $seniorManager, ['brochure.pdf' => 'PDF-BYTES-1']);
        $path = $proposal->attachment_paths[0];

        $send = app(ProposalSendService::class)->recordManualSend(
            $version, $employee, ['a@b.com'], [], null, null, now(), [$path], $this->sendKey()
        );

        $manifest = $send->attachments()->first();
        $this->assertSame('brochure.pdf', $manifest->original_filename);
    }

    public function test_mime_type_preserved(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = null;
        $version = null;
        [$proposal, $version] = $this->releasedProposalWithAttachments($employee, $manager, $seniorManager, []);

        $file = UploadedFile::fake()->create('image.png', 5, 'image/png');
        $path = Storage::disk('local')->putFileAs('proposal-attachments', $file, 'image.png');
        $proposal->forceFill([
            'attachment_paths' => [$path],
            'attachment_names' => [$path => 'image.png'],
        ])->save();

        $send = app(ProposalSendService::class)->recordManualSend(
            $version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [$path], $this->sendKey()
        );

        $manifest = $send->attachments()->first();
        $this->assertStringContainsString('image', $manifest->mime_type);
    }

    public function test_same_bytes_physically_deduplicate_on_disk(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        [$proposal, $version] = $this->releasedProposalWithAttachments($employee, $manager, $seniorManager, [
            'first-name.pdf' => 'IDENTICAL-BYTES',
            'second-name.pdf' => 'IDENTICAL-BYTES',
        ]);

        $send = app(ProposalSendService::class)->recordManualSend(
            $version, $employee, ['a@b.com'], [], null, null, now(), $proposal->attachment_paths, $this->sendKey()
        );

        $manifests = $send->attachments()->get();
        $this->assertSame(2, $manifests->count());
        $this->assertSame($manifests[0]->archived_path, $manifests[1]->archived_path);
    }

    public function test_same_checksum_different_filenames_creates_two_manifest_rows(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        [$proposal, $version] = $this->releasedProposalWithAttachments($employee, $manager, $seniorManager, [
            'first-name.pdf' => 'IDENTICAL-BYTES',
            'second-name.pdf' => 'IDENTICAL-BYTES',
        ]);

        $send = app(ProposalSendService::class)->recordManualSend(
            $version, $employee, ['a@b.com'], [], null, null, now(), $proposal->attachment_paths, $this->sendKey()
        );

        $manifests = $send->attachments()->get();
        $this->assertSame(2, $manifests->count());
        $this->assertEqualsCanonicalizing(['first-name.pdf', 'second-name.pdf'], $manifests->pluck('original_filename')->all());
    }

    public function test_proposal_pdf_itself_is_not_copied_into_the_send_attachment_archive(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        [$proposal, $version] = $this->releasedProposalWithAttachments($employee, $manager, $seniorManager, ['brochure.pdf' => 'PDF-BYTES-1']);
        $path = $proposal->attachment_paths[0];

        $send = app(ProposalSendService::class)->recordManualSend(
            $version, $employee, ['a@b.com'], [], null, null, now(), [$path], $this->sendKey()
        );

        $this->assertSame(1, $send->attachments()->count());

        foreach ($send->attachments as $manifest) {
            $this->assertStringStartsWith('proposal-send-attachments/', $manifest->archived_path);
            $this->assertStringNotContainsString('proposal-pdfs/', $manifest->archived_path);
        }
    }

    public function test_original_attachment_deletion_preserves_archive_and_history(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        [$proposal, $version] = $this->releasedProposalWithAttachments($employee, $manager, $seniorManager, ['brochure.pdf' => 'PDF-BYTES-1']);
        $path = $proposal->attachment_paths[0];

        $send = app(ProposalSendService::class)->recordManualSend(
            $version, $employee, ['a@b.com'], [], null, null, now(), [$path], $this->sendKey()
        );
        $manifest = $send->attachments()->first();
        $archivedPath = $manifest->archived_path;

        // Original attachment is later removed from the Proposal.
        Storage::disk('local')->delete($path);
        $proposal->forceFill(['attachment_paths' => [], 'attachment_names' => []])->save();

        $this->assertTrue(Storage::disk('local')->exists($archivedPath));
        $this->assertDatabaseHas('proposal_send_attachments', ['id' => $manifest->id, 'archived_path' => $archivedPath]);
    }

    public function test_cross_tenant_archive_access_is_denied(): void
    {
        ['employee' => $employeeA, 'manager' => $managerA, 'seniorManager' => $seniorManagerA] = $this->hierarchy();
        [$proposalA, $versionA] = $this->releasedProposalWithAttachments($employeeA, $managerA, $seniorManagerA, ['brochure.pdf' => 'PDF-BYTES-1']);

        $sendA = app(ProposalSendService::class)->recordManualSend(
            $versionA, $employeeA, ['a@b.com'], [], null, null, now(), [$proposalA->attachment_paths[0]], $this->sendKey()
        );

        $organizationB = Organization::factory()->create();
        $managerB = Tenancy::runAs($organizationB->id, fn () => User::factory()->create([
            'organization_id' => $organizationB->id, 'role' => UserRole::Manager,
        ]));

        $foundFromB = Tenancy::runAs($organizationB->id, fn () => ProposalSend::query()->find($sendA->getKey()));

        $this->assertNull($foundFromB);
    }

    public function test_send_ui_form_offers_no_arbitrary_upload_field(): void
    {
        // Structural check on the ManageCommercialVersion page source: the
        // recordManualSend action's form schema uses ONLY CheckboxList
        // (an existing-attachment picker) — never a FileUpload/upload
        // component of any kind (Section L: no new upload inside the Send
        // modal).
        $source = file_get_contents(app_path('Filament/Resources/ProposalResource/Pages/ManageCommercialVersion.php'));

        $recordSendStart = strpos($source, "Actions\\Action::make('recordManualSend')");
        $this->assertNotFalse($recordSendStart);

        $nextActionStart = strpos($source, 'Actions\\Action::make(', $recordSendStart + 1) ?: strlen($source);
        $recordSendBlock = substr($source, $recordSendStart, $nextActionStart - $recordSendStart);

        $this->assertStringNotContainsString('FileUpload::make', $recordSendBlock);
    }

    public function test_archiver_reuses_an_already_written_object_when_concurrently_written(): void
    {
        $archiver = app(ProposalSendAttachmentArchiver::class);

        $first = $archiver->archive(1, 'SAME-BYTES');
        $second = $archiver->archive(1, 'SAME-BYTES');

        $this->assertSame($first['archivedPath'], $second['archivedPath']);
        $this->assertSame($first['checksum'], $second['checksum']);
        $this->assertTrue(Storage::disk('local')->exists($first['archivedPath']));
    }
}
