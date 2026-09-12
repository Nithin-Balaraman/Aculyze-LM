<?php

namespace Tests\Feature;

use App\Enums\ProposalVersionLifecycle;
use App\Enums\UserRole;
use App\Models\Lead;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * Phase 4A-3.3 hardening pass: idempotency attachment identity must compare
 * (original_filename, checksum_sha256) pairs, not filename alone — and
 * reusing an existing content-addressed archive object must be verified
 * against its own real stored bytes, never trusted on path/size alone.
 */
class ProposalSendIdempotencyHardeningTest extends TestCase
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
        return (string) Str::uuid();
    }

    // --- A: idempotency conflict when the live attachment's bytes changed ---

    public function test_retry_with_same_key_but_swapped_attachment_bytes_is_rejected(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        [$proposal, $version] = $this->releasedProposalWithAttachments($employee, $manager, $seniorManager, ['catalogue.pdf' => 'BYTES-A']);
        $path = $proposal->attachment_paths[0];
        $key = $this->sendKey();

        $original = app(ProposalSendService::class)->recordManualSend(
            $version, $employee, ['a@b.com'], [], null, null, now(), [$path], $key
        );
        $originalManifestId = $original->attachments()->first()->id;

        // The live Proposal attachment is replaced with different bytes
        // under the SAME filename before the retry.
        Storage::disk('local')->put($path, 'BYTES-B');

        try {
            app(ProposalSendService::class)->recordManualSend(
                $version->fresh(['proposal']), $employee, ['a@b.com'], [], null, null, now(), [$path], $key
            );
            $this->fail('Expected a LogicException rejecting the conflicting idempotency replay.');
        } catch (LogicException $e) {
            $this->assertSame(1, ProposalSend::query()->count());
            $this->assertDatabaseHas('proposal_send_attachments', [
                'id' => $originalManifestId,
                'checksum_sha256' => hash('sha256', 'BYTES-A'),
            ]);
        }
    }

    // --- B: idempotency replay with identical filename/bytes reuses the existing send ---

    public function test_retry_with_same_key_and_identical_attachment_bytes_reuses_existing_send(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        [$proposal, $version] = $this->releasedProposalWithAttachments($employee, $manager, $seniorManager, ['catalogue.pdf' => 'BYTES-A']);
        $path = $proposal->attachment_paths[0];
        $key = $this->sendKey();

        $first = app(ProposalSendService::class)->recordManualSend(
            $version, $employee, ['a@b.com'], [], null, null, now(), [$path], $key
        );
        $second = app(ProposalSendService::class)->recordManualSend(
            $version->fresh(['proposal']), $employee, ['a@b.com'], [], null, null, now(), [$path], $key
        );

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, ProposalSend::query()->count());
    }

    // --- C: selecting the same attachments in a different order is still the same payload ---

    public function test_retry_with_attachments_selected_in_a_different_order_is_not_a_conflict(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        [$proposal, $version] = $this->releasedProposalWithAttachments($employee, $manager, $seniorManager, [
            'alpha.pdf' => 'ALPHA-BYTES',
            'beta.pdf' => 'BETA-BYTES',
        ]);
        [$alphaPath, $betaPath] = $proposal->attachment_paths;
        $key = $this->sendKey();

        $first = app(ProposalSendService::class)->recordManualSend(
            $version, $employee, ['a@b.com'], [], null, null, now(), [$alphaPath, $betaPath], $key
        );
        $second = app(ProposalSendService::class)->recordManualSend(
            $version->fresh(['proposal']), $employee, ['a@b.com'], [], null, null, now(), [$betaPath, $alphaPath], $key
        );

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, ProposalSend::query()->count());
    }

    // --- D: an existing genuinely-matching archive object is safely reused ---

    public function test_existing_archive_object_with_matching_bytes_is_reused(): void
    {
        $archiver = app(ProposalSendAttachmentArchiver::class);

        $first = $archiver->archive(7, 'GENUINE-BYTES');
        $second = $archiver->archive(7, 'GENUINE-BYTES');

        $this->assertSame($first['archivedPath'], $second['archivedPath']);
        $this->assertSame($first['checksum'], $second['checksum']);
        $this->assertSame(hash('sha256', 'GENUINE-BYTES'), $second['checksum']);
    }

    // --- E: a canonical path whose stored bytes do NOT match its own checksum fails safely ---

    public function test_corrupt_canonical_archive_object_fails_safely_without_overwriting(): void
    {
        $bytes = 'REAL-ATTACHMENT-BYTES';
        $checksum = hash('sha256', $bytes);
        $canonicalPath = "proposal-send-attachments/9/{$checksum}";

        // Simulate corruption/collision: something else occupies the
        // canonical path with DIFFERENT bytes than its own checksum implies.
        Storage::disk('local')->put($canonicalPath, 'WRONG-BYTES-AT-THIS-PATH');

        $archiver = app(ProposalSendAttachmentArchiver::class);

        try {
            $archiver->archive(9, $bytes);
            $this->fail('Expected a LogicException for a checksum-mismatched existing archive object.');
        } catch (LogicException $e) {
            // The suspect object must be left exactly as it was found.
            $this->assertSame('WRONG-BYTES-AT-THIS-PATH', Storage::disk('local')->get($canonicalPath));
        }
    }

    public function test_corrupt_canonical_archive_object_blocks_the_whole_send_with_no_manifest_created(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        [$proposal, $version] = $this->releasedProposalWithAttachments($employee, $manager, $seniorManager, ['catalogue.pdf' => 'REAL-BYTES']);
        $path = $proposal->attachment_paths[0];

        $checksum = hash('sha256', 'REAL-BYTES');
        $canonicalPath = "proposal-send-attachments/{$proposal->organization_id}/{$checksum}";
        Storage::disk('local')->put($canonicalPath, 'CORRUPTED-BYTES');

        try {
            app(ProposalSendService::class)->recordManualSend(
                $version, $employee, ['a@b.com'], [], null, null, now(), [$path], $this->sendKey()
            );
            $this->fail('Expected the send to fail against a corrupted canonical archive object.');
        } catch (LogicException $e) {
            $this->assertSame(0, ProposalSend::query()->count());
            $this->assertDatabaseCount('proposal_send_attachments', 0);
            $this->assertSame('CORRUPTED-BYTES', Storage::disk('local')->get($canonicalPath));
        }
    }

    // --- F: the existing duplicate-content rule still holds after hardening ---

    public function test_same_bytes_different_filenames_still_produce_one_object_and_two_manifests(): void
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
        $this->assertEqualsCanonicalizing(['first-name.pdf', 'second-name.pdf'], $manifests->pluck('original_filename')->all());
    }

    // --- A legitimate fresh resend with a NEW key still works after a rejected replay ---

    public function test_fresh_idempotency_key_still_permits_a_legitimate_resend_after_a_rejected_replay(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        [$proposal, $version] = $this->releasedProposalWithAttachments($employee, $manager, $seniorManager, ['catalogue.pdf' => 'BYTES-A']);
        $path = $proposal->attachment_paths[0];
        $key = $this->sendKey();

        app(ProposalSendService::class)->recordManualSend(
            $version, $employee, ['a@b.com'], [], null, null, now(), [$path], $key
        );

        Storage::disk('local')->put($path, 'BYTES-B');

        try {
            app(ProposalSendService::class)->recordManualSend(
                $version->fresh(['proposal']), $employee, ['a@b.com'], [], null, null, now(), [$path], $key
            );
        } catch (LogicException) {
        }

        // A genuine resend with a fresh key against the now-current
        // attachment content must still succeed normally.
        $resend = app(ProposalSendService::class)->recordManualSend(
            $version->fresh(['proposal']), $employee, ['a@b.com'], [], null, null, now(), [$path], $this->sendKey()
        );

        $this->assertSame(2, ProposalSend::query()->count());
        $this->assertSame(hash('sha256', 'BYTES-B'), $resend->attachments()->first()->checksum_sha256);
    }
}
