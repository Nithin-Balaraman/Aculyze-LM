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
 * temp copy and reuses what's there) or race the rename — since the bytes
 * are byte-identical under the same checksum by construction, a rename
 * "losing" the race and overwriting the winner's identical bytes is
 * harmless; this class never overwrites a checksum path with DIFFERENT
 * bytes (a rename that lost the exists() check falls into the "reuse what
 * is there" branch instead of blindly moving).
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
            return $this->verifiedResult($canonicalPath, $checksum, $expectedSize);
        }

        $tempPath = $canonicalPath.'.tmp-'.bin2hex(random_bytes(16));

        try {
            $disk->put($tempPath, $bytes);

            if ($disk->exists($canonicalPath)) {
                // Lost the race to a concurrent archive() call writing the
                // exact same checksum path — reuse what is already there
                // rather than overwriting it, and discard our own copy.
                $disk->delete($tempPath);

                return $this->verifiedResult($canonicalPath, $checksum, $expectedSize);
            }

            $disk->move($tempPath, $canonicalPath);
        } catch (Throwable $e) {
            $disk->delete($tempPath);

            throw $e;
        }

        return $this->verifiedResult($canonicalPath, $checksum, $expectedSize);
    }

    /** @return array{archivedPath: string, checksum: string, byteSize: int} */
    private function verifiedResult(string $canonicalPath, string $checksum, int $expectedSize): array
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
