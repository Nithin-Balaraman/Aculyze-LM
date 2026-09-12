<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use LogicException;
use Throwable;

/**
 * Phase 4A-3.3, Sections L/M: safe write-if-absent content-addressed
 * storage for immutable send-attachment archive bytes. Deliberately no
 * archive-object DB table — the deterministic path itself
 * (`proposal-send-attachments/{organization_id}/{sha256}`) IS the dedup
 * mechanism; `proposal_send_attachments` (the manifest) is a separate,
 * always-created-per-selection concern (see ProposalSendService), never
 * deduplicated by this class.
 *
 * Write-if-absent pattern: write the bytes under a uniquely-named temp
 * path first, then move it into the canonical checksum path only if that
 * path is still absent. Two concurrent archive() calls for the same bytes
 * either both see the path already occupied (the loser discards its own
 * temp copy and reuses what's there) or race the rename.
 *
 * Idempotency/archive hardening pass: reusing an EXISTING canonical object
 * (found up front, or found after losing the create race) is never trusted
 * on path/size alone — its actual stored bytes are read back and
 * re-hashed, and only an exact SHA-256 match against the checksum this
 * call itself computed is treated as reusable evidence. A path collision
 * with different bytes (corruption, or an astronomically unlikely genuine
 * hash collision) is surfaced as a controlled integrity failure — the
 * existing object is never overwritten and no caller ever gets a result
 * pointing at unverified bytes.
 */
class ProposalSendAttachmentArchiver
{
    /**
     * @return array{archivedPath: string, checksum: string, byteSize: int}
     */
    public function archive(int $organizationId, string $bytes): array
    {
        $checksum = hash('sha256', $bytes);
        $canonicalPath = sprintf('proposal-send-attachments/%d/%s', $organizationId, $checksum);
        $expectedSize = strlen($bytes);

        $disk = Storage::disk('local');

        if ($disk->exists($canonicalPath)) {
            return $this->reuseVerifiedExisting($canonicalPath, $checksum, $expectedSize);
        }

        $tempPath = $canonicalPath.'.tmp-'.bin2hex(random_bytes(16));

        try {
            $disk->put($tempPath, $bytes);

            if ($disk->exists($canonicalPath)) {
                // Lost the race to a concurrent archive() call writing the
                // exact same checksum path — reuse what is already there
                // (checksum-verified below) rather than overwriting it, and
                // discard our own copy.
                $disk->delete($tempPath);

                return $this->reuseVerifiedExisting($canonicalPath, $checksum, $expectedSize);
            }

            $disk->move($tempPath, $canonicalPath);
        } catch (Throwable $e) {
            $disk->delete($tempPath);

            throw $e;
        }

        return $this->verifyFreshlyWritten($canonicalPath, $checksum, $expectedSize);
    }

    /**
     * The canonical path already existed — either found immediately, or a
     * concurrent writer won the create race. Read the existing bytes back
     * and verify both size and SHA-256 before treating the object as
     * reusable: this is the defense-in-depth check for immutable evidence
     * — a mismatch here means something is wrong with a path that is
     * supposed to be content-addressed, and must never be silently
     * papered over or overwritten.
     *
     * @return array{archivedPath: string, checksum: string, byteSize: int}
     */
    private function reuseVerifiedExisting(string $canonicalPath, string $expectedChecksum, int $expectedSize): array
    {
        $disk = Storage::disk('local');

        $storedSize = $disk->size($canonicalPath);

        if ($storedSize !== $expectedSize) {
            throw new LogicException(
                "Existing archived attachment at {$canonicalPath} has byte size {$storedSize}, expected {$expectedSize} — refusing to reuse a mismatched archive object. The existing object was left untouched."
            );
        }

        $storedChecksum = hash('sha256', $disk->get($canonicalPath));

        if ($storedChecksum !== $expectedChecksum) {
            throw new LogicException(
                "Existing archived attachment at {$canonicalPath} does not match its own checksum (expected {$expectedChecksum}, found {$storedChecksum}) — refusing to reuse or overwrite this immutable archive object. The existing object was left untouched."
            );
        }

        return ['archivedPath' => $canonicalPath, 'checksum' => $expectedChecksum, 'byteSize' => $storedSize];
    }

    /** A brand-new object this call itself just wrote — verify it landed correctly before trusting it. */
    private function verifyFreshlyWritten(string $canonicalPath, string $checksum, int $expectedSize): array
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($canonicalPath)) {
            throw new LogicException("Could not archive attachment bytes to private storage at {$canonicalPath}.");
        }

        $storedSize = $disk->size($canonicalPath);

        if ($storedSize !== $expectedSize) {
            throw new LogicException(
                "Archived attachment at {$canonicalPath} has byte size {$storedSize}, expected {$expectedSize} — refusing to trust a mismatched archive object."
            );
        }

        return ['archivedPath' => $canonicalPath, 'checksum' => $checksum, 'byteSize' => $storedSize];
    }
}
