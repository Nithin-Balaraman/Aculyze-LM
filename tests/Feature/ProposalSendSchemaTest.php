<?php

namespace Tests\Feature;

use App\Enums\ProposalPdfArtifactStatus;
use App\Enums\ProposalSendMethod;
use App\Enums\ProposalSendStatus;
use App\Enums\ProposalVersionLifecycle;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalPdfArtifact;
use App\Models\ProposalSend;
use App\Models\ProposalSendAttachment;
use App\Models\ProposalVersion;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4A-3.1: proposal_sends / proposal_send_attachments schema shape.
 * No send service exists yet (4A-3.3) — this exercises the constraints
 * directly. Locked Decision 23: no unique-per-Version successful-send
 * constraint of any kind. Locked Decision 24: manifest rows are never
 * deduplicated by checksum alone.
 */
class ProposalSendSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function versionWithArtifact(User $actor): ProposalVersion
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $actor->id, 'created_by' => $actor->id]);
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $actor->id,
            'created_by' => $actor->id,
            'stage' => 'validated',
            'temperature' => 'hot',
            'notes' => 'Fixture.',
        ]);
        $proposal = Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $actor->id,
            'created_by' => $actor->id,
            'stage' => 'sent',
        ]);

        $version = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'lifecycle_status' => ProposalVersionLifecycle::Sent,
        ]);

        $proposal->forceFill(['current_version_id' => $version->id])->save();

        ProposalPdfArtifact::create([
            'proposal_version_id' => $version->id,
            'status' => ProposalPdfArtifactStatus::Success,
            'template_version' => 'v1',
            'checksum_sha256' => str_repeat('a', 64),
            'storage_path' => 'proposal-pdf-artifacts/1/1.pdf',
            'byte_size' => 1024,
            'generated_at' => now(),
            'generated_by' => $actor->id,
        ]);

        return $version->fresh(['proposal']);
    }

    private function send(ProposalVersion $version, User $actor, array $overrides = []): ProposalSend
    {
        $artifact = ProposalPdfArtifact::query()->where('proposal_version_id', $version->id)->firstOrFail();

        return ProposalSend::create(array_merge([
            'proposal_id' => $version->proposal_id,
            'proposal_version_id' => $version->id,
            'pdf_artifact_id' => $artifact->id,
            'method' => ProposalSendMethod::Manual,
            'status' => ProposalSendStatus::Sent,
            'to_recipients' => ['client@example.com'],
            'attempted_at' => now(),
            'attempted_by' => $actor->id,
            'sent_at' => now(),
            'sent_by' => $actor->id,
            'idempotency_key' => (string) str()->uuid(),
        ], $overrides));
    }

    public function test_no_sent_lock_key_column_exists(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('proposal_sends', 'sent_lock_key'));
    }

    public function test_multiple_sends_are_allowed_for_the_same_version(): void
    {
        $actor = User::factory()->create();
        $version = $this->versionWithArtifact($actor);

        $this->send($version, $actor);
        $this->send($version, $actor);
        $this->send($version, $actor);

        $this->assertSame(3, ProposalSend::query()->where('proposal_version_id', $version->id)->count());
    }

    public function test_idempotency_key_is_unique(): void
    {
        $actor = User::factory()->create();
        $version = $this->versionWithArtifact($actor);
        $key = (string) str()->uuid();

        $this->send($version, $actor, ['idempotency_key' => $key]);

        $this->expectException(QueryException::class);
        $this->send($version, $actor, ['idempotency_key' => $key]);
    }

    public function test_same_checksum_may_appear_multiple_times_in_one_send_manifest(): void
    {
        $actor = User::factory()->create();
        $version = $this->versionWithArtifact($actor);
        $send = $this->send($version, $actor);

        $checksum = str_repeat('b', 64);

        ProposalSendAttachment::create([
            'proposal_send_id' => $send->id,
            'checksum_sha256' => $checksum,
            'archived_path' => "proposal-send-attachments/{$send->organization_id}/{$checksum}",
            'original_filename' => 'Quote.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => 2048,
        ]);

        ProposalSendAttachment::create([
            'proposal_send_id' => $send->id,
            'checksum_sha256' => $checksum,
            'archived_path' => "proposal-send-attachments/{$send->organization_id}/{$checksum}",
            'original_filename' => 'Quote (copy).pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => 2048,
        ]);

        $this->assertSame(2, ProposalSendAttachment::query()->where('proposal_send_id', $send->id)->count());
        $this->assertSame(2, ProposalSendAttachment::query()->where('checksum_sha256', $checksum)->count());
    }
}
