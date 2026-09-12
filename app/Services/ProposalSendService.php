<?php

namespace App\Services;

use App\Enums\ProposalSendMethod;
use App\Enums\ProposalSendStatus;
use App\Enums\ProposalStage;
use App\Enums\ProposalVersionLifecycle;
use App\Models\Proposal;
use App\Models\ProposalSend;
use App\Models\ProposalSendAttachment;
use App\Models\ProposalVersion;
use App\Models\User;
use App\Policies\ProposalSendPolicy;
use App\Support\Audit\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * Phase 4A-3.3, Sections G-O: a CONTROLLED RECORDING of a send that already
 * happened manually, outside Aculyze-LM — never an SMTP/Outlook/Graph send,
 * never delivery confirmation. Locked Decision 23: the same unchanged
 * Version may be successfully sent more than once — there is deliberately
 * no unique-per-Version "sent lock" of any kind; `idempotency_key` is a
 * per-OPERATION dedupe key only (a retried/double-submitted click reuses
 * the same key and is a no-op, a genuine later resend always mints a fresh
 * one).
 *
 * A validation failure here writes NO `proposal_sends` row at all — never
 * a Failed row (ProposalSendStatus::Failed is reserved for a genuine
 * provider/execution attempt in Graph, 4B, which does not exist yet).
 */
class ProposalSendService
{
    /**
     * @param  array<int, string>  $toRecipients
     * @param  array<int, string>  $ccRecipients
     * @param  array<int, string>  $selectedAttachmentPaths  Existing Proposal::attachment_paths entries to archive and attach.
     */
    public function recordManualSend(
        ProposalVersion $version,
        User $actor,
        array $toRecipients,
        array $ccRecipients,
        ?string $subject,
        ?string $notes,
        CarbonInterface $sentAt,
        array $selectedAttachmentPaths,
        string $idempotencyKey,
    ): ProposalSend {
        $toRecipients = $this->normalizeRecipients($toRecipients);
        $ccRecipients = $this->normalizeRecipients($ccRecipients);

        $version = $version->fresh(['proposal']);
        $proposal = $version->proposal;

        // A retried/double-submitted click reuses the same key — reused
        // BEFORE any other validation runs, so a benign retry of an
        // already-successful send can never fail merely because state
        // moved on in the meantime (Section O).
        $existing = ProposalSend::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            $incomingIdentity = $this->liveAttachmentIdentity($proposal, $selectedAttachmentPaths);
            $this->assertSamePayload($existing, $version, $toRecipients, $ccRecipients, $subject, $notes, $sentAt, $incomingIdentity);

            return $existing;
        }

        $this->assertSendPreconditions($version, $proposal, $actor, $toRecipients, $sentAt);

        // PREP (Section N): resolve and archive immutable attachment bytes
        // BEFORE the DB transaction — never inside the lock.
        $archivedAttachments = $this->archiveSelectedAttachments($proposal, $selectedAttachmentPaths);

        return DB::transaction(function () use ($version, $proposal, $actor, $toRecipients, $ccRecipients, $subject, $notes, $sentAt, $archivedAttachments, $idempotencyKey) {
            $lockedProposal = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();
            $lockedVersion = ProposalVersion::query()->whereKey($version->getKey())->lockForUpdate()->firstOrFail();
            $lockedVersion->setRelation('proposal', $lockedProposal);

            // Re-check the idempotency key under the lock — closes the gap
            // between the pre-lock check above and acquiring the lock.
            $raceExisting = ProposalSend::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($raceExisting !== null) {
                // Reuse the checksums this call already computed while
                // archiving (Section N: PREP happened before this lock)
                // rather than re-reading the same bytes from disk again.
                $incomingIdentity = $this->canonicalIdentity(
                    collect($archivedAttachments)->map(fn (array $meta) => [$meta['originalFilename'], $meta['checksum']])->all()
                );
                $this->assertSamePayload($raceExisting, $lockedVersion, $toRecipients, $ccRecipients, $subject, $notes, $sentAt, $incomingIdentity);

                return $raceExisting;
            }

            // Re-validate every precondition against the freshly locked
            // state — never trust what was true before the lock was held.
            $this->assertSendPreconditions($lockedVersion, $lockedProposal, $actor, $toRecipients, $sentAt);

            $currentPrimary = app(ProposalPdfArtifactService::class)->currentPrimary($lockedVersion);

            $isFirstSend = $lockedVersion->lifecycle_status === ProposalVersionLifecycle::Approved;

            $send = ProposalSend::create([
                'proposal_id' => $lockedProposal->getKey(),
                'proposal_version_id' => $lockedVersion->getKey(),
                'pdf_artifact_id' => $currentPrimary->getKey(),
                'method' => ProposalSendMethod::Manual,
                'status' => ProposalSendStatus::Sent,
                'to_recipients' => $toRecipients,
                'cc_recipients' => $ccRecipients === [] ? null : $ccRecipients,
                'subject' => $subject,
                'body' => null,
                'notes' => $notes,
                'attempted_at' => now(),
                'attempted_by' => $actor->getKey(),
                'sent_at' => $sentAt,
                'sent_by' => $actor->getKey(),
                'idempotency_key' => $idempotencyKey,
            ]);

            foreach ($archivedAttachments as $path => $meta) {
                ProposalSendAttachment::create([
                    'proposal_send_id' => $send->getKey(),
                    'checksum_sha256' => $meta['checksum'],
                    'archived_path' => $meta['archivedPath'],
                    'original_filename' => $meta['originalFilename'],
                    'mime_type' => $meta['mimeType'],
                    'byte_size' => $meta['byteSize'],
                ]);
            }

            if ($isFirstSend) {
                $lockedVersion->forceFill([
                    'lifecycle_status' => ProposalVersionLifecycle::Sent,
                    'sent_at' => $lockedVersion->sent_at ?? $sentAt,
                ])->save();

                if ($lockedProposal->stage !== ProposalStage::Sent) {
                    $lockedProposal->forceFill(['stage' => ProposalStage::Sent])->save();
                }

                AuditLogger::record(
                    entityType: 'ProposalVersion',
                    entityId: $lockedVersion->getKey(),
                    action: 'proposal_version_first_sent',
                    organizationId: $lockedVersion->organization_id,
                    before: ['lifecycle_status' => ProposalVersionLifecycle::Approved->value],
                    after: ['lifecycle_status' => ProposalVersionLifecycle::Sent->value, 'proposal_send_id' => $send->getKey()],
                );
            }

            AuditLogger::record(
                entityType: 'ProposalSend',
                entityId: $send->getKey(),
                action: $isFirstSend ? 'proposal_send_recorded_first' : 'proposal_send_recorded_resend',
                organizationId: $lockedVersion->organization_id,
                after: [
                    'proposal_version_id' => $lockedVersion->getKey(),
                    'pdf_artifact_id' => $currentPrimary->getKey(),
                    'to_recipients' => $toRecipients,
                    'sent_at' => $sentAt->toIso8601String(),
                ],
            );

            return $send;
        });
    }

    /** @param  array<int, string>  $toRecipients */
    private function assertSendPreconditions(ProposalVersion $version, Proposal $proposal, User $actor, array $toRecipients, CarbonInterface $sentAt): void
    {
        if ($proposal->current_version_id !== $version->getKey()) {
            throw new LogicException("ProposalVersion #{$version->getKey()} is no longer Proposal #{$proposal->getKey()}'s current Version — reload the latest state before retrying.");
        }

        if (! in_array($version->lifecycle_status, [ProposalVersionLifecycle::Approved, ProposalVersionLifecycle::Sent], true)) {
            throw new LogicException("ProposalVersion #{$version->getKey()} cannot be sent: its lifecycle is {$version->lifecycle_status->value}, not approved or sent.");
        }

        if ($version->is_legacy_backfill) {
            throw new LogicException("ProposalVersion #{$version->getKey()} is a legacy backfilled Version and can never be sent through this service.");
        }

        if (! app(ProposalSendPolicy::class)->send($actor, $version)) {
            throw new LogicException("You are not authorized to record a manual send for ProposalVersion #{$version->getKey()}.");
        }

        if ($version->released_at === null) {
            throw new LogicException("ProposalVersion #{$version->getKey()} has not been Released for client sending yet.");
        }

        if ($version->isReleaseStale()) {
            throw new LogicException("ProposalVersion #{$version->getKey()}'s Release is stale — the final PDF was corrected since the last Release. Ask a Manager/Senior Manager to Re-Release before sending.");
        }

        $currentPrimary = app(ProposalPdfArtifactService::class)->currentPrimary($version);

        if ($currentPrimary === null || $currentPrimary->getKey() !== $version->released_pdf_artifact_id) {
            throw new LogicException("ProposalVersion #{$version->getKey()}'s released PDF artifact no longer matches its current primary artifact.");
        }

        if ($toRecipients === []) {
            throw new LogicException('At least one To recipient is required to record a manual send.');
        }

        if ($sentAt->greaterThan(now())) {
            throw new LogicException('The sent date/time cannot be in the future.');
        }

        if ($version->released_at !== null && $sentAt->lessThan($version->released_at)) {
            throw new LogicException('The sent date/time cannot be earlier than when this Version was Released.');
        }
    }

    /**
     * @param  array<int, string>  $selectedAttachmentPaths
     * @return array<string, array{originalFilename: string, mimeType: string, byteSize: int, checksum: string, archivedPath: string}>
     */
    private function archiveSelectedAttachments(Proposal $proposal, array $selectedAttachmentPaths): array
    {
        if ($selectedAttachmentPaths === []) {
            return [];
        }

        $validAttachments = $proposal->attachments();
        $disk = Storage::disk('local');
        $archiver = app(ProposalSendAttachmentArchiver::class);

        $result = [];

        foreach ($selectedAttachmentPaths as $path) {
            if (! array_key_exists($path, $validAttachments)) {
                throw new LogicException("'{$path}' is not a current attachment on Proposal #{$proposal->getKey()}.");
            }

            if (! $disk->exists($path)) {
                throw new LogicException("Attachment '{$path}' could not be read from private storage.");
            }

            $bytes = $disk->get($path);
            $mimeType = $disk->mimeType($path) ?: 'application/octet-stream';

            $archived = $archiver->archive($proposal->organization_id, $bytes);

            $result[$path] = [
                'originalFilename' => $validAttachments[$path],
                'mimeType' => $mimeType,
                'byteSize' => $archived['byteSize'],
                'checksum' => $archived['checksum'],
                'archivedPath' => $archived['archivedPath'],
            ];
        }

        return $result;
    }

    /**
     * Section O: a retried/double-submitted click reusing the same
     * idempotency key must reuse the existing send silently; a fresh
     * idempotency key attached to a MATERIALLY DIFFERENT payload must be
     * rejected outright, never silently reused. There is no dedicated
     * request_fingerprint column (Section O explicitly forbids adding one
     * without a STOP), so this compares the incoming call's own arguments
     * against the already-persisted row's material fields directly.
     *
     * Hardening pass: attachment identity is compared as (original_filename,
     * checksum_sha256) PAIRS, not filename alone — a filename-only
     * comparison could not distinguish a replay against a since-swapped
     * Proposal attachment (same name, different bytes) from a genuine
     * repeat of the same operation. The comparison is a canonical,
     * order-independent, duplicate-preserving multiset (see
     * canonicalIdentity()), so selecting the same attachments in a
     * different order never causes a false conflict, and two distinct
     * manifest rows that legitimately share both filename and checksum are
     * still correctly matched one-for-one rather than collapsed.
     *
     * @param  array<int, string>  $toRecipients
     * @param  array<int, string>  $ccRecipients
     * @param  array<int, string>  $incomingAttachmentIdentity  Canonicalized via canonicalIdentity().
     */
    private function assertSamePayload(
        ProposalSend $existing,
        ProposalVersion $version,
        array $toRecipients,
        array $ccRecipients,
        ?string $subject,
        ?string $notes,
        CarbonInterface $sentAt,
        array $incomingAttachmentIdentity,
    ): void {
        $existingIdentity = $this->canonicalIdentity(
            $existing->attachments()->get()->map(fn ($manifest) => [$manifest->original_filename, $manifest->checksum_sha256])->all()
        );

        // sent_at is compared at whole-SECOND precision, not exact
        // microsecond equality: the `proposal_sends.sent_at` column stores
        // no fractional seconds, so a round-tripped value read back from
        // the database would otherwise never exactly equal() the
        // in-memory Carbon instance passed into a genuine retry.
        $materiallyIdentical = $existing->proposal_version_id === $version->getKey()
            && $existing->to_recipients === $toRecipients
            && ($existing->cc_recipients ?? []) === $ccRecipients
            && $existing->subject === $subject
            && $existing->notes === $notes
            && $existing->sent_at !== null
            && $existing->sent_at->timestamp === $sentAt->timestamp
            && $incomingAttachmentIdentity === $existingIdentity;

        if (! $materiallyIdentical) {
            throw new LogicException(
                "Idempotency key '{$existing->idempotency_key}' was already used for a different send — use a fresh idempotency key for a new send."
            );
        }
    }

    /**
     * @param  array<int, string>  $recipients
     * @return array<int, string>
     */
    private function normalizeRecipients(array $recipients): array
    {
        return collect($recipients)
            ->map(fn ($email) => is_string($email) ? trim($email) : $email)
            ->filter(fn ($email) => filled($email))
            ->values()
            ->all();
    }

    /**
     * Reads the CURRENT live bytes of each selected Proposal attachment
     * path and derives its (filename, checksum) identity pair — used only
     * for the pre-lock idempotency replay check (before anything has been
     * archived yet in this call), so a replay against a since-swapped
     * attachment (same filename, different bytes) is detected from the
     * attachment's real current content, not merely its name.
     *
     * @param  array<int, string>  $selectedAttachmentPaths
     * @return array<int, string> Canonicalized via canonicalIdentity().
     */
    private function liveAttachmentIdentity(Proposal $proposal, array $selectedAttachmentPaths): array
    {
        if ($selectedAttachmentPaths === []) {
            return [];
        }

        $validAttachments = $proposal->attachments();
        $disk = Storage::disk('local');

        $pairs = collect($selectedAttachmentPaths)
            ->map(function (string $path) use ($validAttachments, $disk, $proposal) {
                if (! array_key_exists($path, $validAttachments)) {
                    throw new LogicException("'{$path}' is not a current attachment on Proposal #{$proposal->getKey()}.");
                }

                if (! $disk->exists($path)) {
                    throw new LogicException("Attachment '{$path}' could not be read from private storage.");
                }

                return [$validAttachments[$path], hash('sha256', $disk->get($path))];
            })
            ->all();

        return $this->canonicalIdentity($pairs);
    }

    /**
     * Turns a list of [filename, checksum] pairs into a canonical,
     * order-independent identity list for comparison: sorting the combined
     * "filename|checksum" strings makes selection ORDER irrelevant while
     * still preserving DUPLICATES (a multiset, not a set) — two distinct
     * manifest rows that legitimately share the same filename and checksum
     * must still both be accounted for on each side of the comparison,
     * never collapsed into one.
     *
     * @param  array<int, array{0: string, 1: string}>  $filenameChecksumPairs
     * @return array<int, string>
     */
    private function canonicalIdentity(array $filenameChecksumPairs): array
    {
        return collect($filenameChecksumPairs)
            ->map(fn (array $pair) => $pair[0].'|'.$pair[1])
            ->sort()
            ->values()
            ->all();
    }
}
