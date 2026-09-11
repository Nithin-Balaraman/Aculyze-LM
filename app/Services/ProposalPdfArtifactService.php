<?php

namespace App\Services;

use App\Enums\ProposalPdfArtifactStatus;
use App\Enums\ProposalVersionLifecycle;
use App\Models\ProposalPdfArtifact;
use App\Models\ProposalVersion;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Organization\OrganizationIdentityResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Throwable;

/**
 * Phase 4A-3.2: the sole owner of `proposal_pdf_artifacts` writes. Renders
 * the final customer-facing Proposal PDF ONLY from the frozen
 * ProposalVersion snapshot and its owned lines/tax components — never from
 * the live Prospect (locked Decision 2's "stored PDF bytes + checksum are
 * the historical truth" and the Section D snapshot-only rule).
 *
 * Two entry points:
 * - generateIfMissing(): normal first generation / retry-after-failure.
 *   Approved lifecycle only. Idempotent — a Version that already has a
 *   current successful primary artifact is returned untouched, never
 *   re-rendered.
 * - correct(): rendering-only correction of an EXISTING current primary.
 *   Approved OR Sent lifecycle. Requires a mandatory correction reason and
 *   an existing current primary to replace. Never changes
 *   ProposalVersionLifecycle.
 *
 * Both share the same two-phase safety ordering (locked Section I): PHASE 1
 * renders and stores replacement bytes with NO database lock held at all;
 * only once that has fully succeeded does PHASE 2 open a short transaction
 * to lock the Version, re-verify preconditions, and atomically swap the
 * primary. The old successful primary is never superseded before the
 * replacement render has succeeded, and is never deleted — permanent,
 * append-only history.
 */
class ProposalPdfArtifactService
{
    public const TEMPLATE_VERSION = 'proposal-v1';

    /**
     * Normal first-generation / retry path. Approved lifecycle only,
     * non-legacy. If a current successful primary already exists, returns
     * it unchanged rather than generating a duplicate — replacing an
     * existing primary is only ever done via correct().
     *
     * Never throws for an ordinary rendering/identity failure — that is
     * recorded as a permanent Failed artifact row and returned normally,
     * so a caller (including the auto-generation hook run after an
     * approval commits) never has to treat "generation failed" as an
     * exceptional condition.
     *
     * @throws LogicException Only for a precondition that means this call should never have been
     *                        reachable at all (wrong lifecycle, legacy Version, cross-organization actor).
     */
    public function generateIfMissing(ProposalVersion $version, User $actor): ProposalPdfArtifact
    {
        $version = $version->fresh();

        $this->assertSameOrganization($version, $actor);
        $this->assertNotLegacy($version);

        if ($version->lifecycle_status !== ProposalVersionLifecycle::Approved) {
            throw new LogicException(
                "ProposalVersion #{$version->getKey()} cannot generate a final PDF: its lifecycle is {$version->lifecycle_status->value}, not approved."
            );
        }

        $existingPrimary = $this->currentPrimary($version);

        if ($existingPrimary !== null) {
            return $existingPrimary;
        }

        try {
            $rendered = $this->renderAndStore($version);
        } catch (Throwable $e) {
            return $this->recordFailure($version, $actor, $e->getMessage(), correctionReason: null);
        }

        // Short lock-and-insert — the generated-column UNIQUE backstop
        // (primary_lock_key) makes a genuine duplicate impossible even if
        // this race check somehow missed a concurrent winner; re-checking
        // here just avoids wasting the render/store work that already
        // happened and gives a clean, expected return value either way.
        return DB::transaction(function () use ($version, $actor, $rendered) {
            $lockedVersion = ProposalVersion::query()->whereKey($version->getKey())->lockForUpdate()->firstOrFail();

            $concurrentPrimary = $this->currentPrimary($lockedVersion);

            if ($concurrentPrimary !== null) {
                $this->deleteBytes($rendered['storagePath']);

                return $concurrentPrimary;
            }

            $artifact = ProposalPdfArtifact::create([
                'proposal_version_id' => $lockedVersion->getKey(),
                'status' => ProposalPdfArtifactStatus::Success,
                'template_version' => self::TEMPLATE_VERSION,
                'checksum_sha256' => $rendered['checksum'],
                'storage_path' => $rendered['storagePath'],
                'byte_size' => $rendered['byteSize'],
                'generated_at' => now(),
                'generated_by' => $actor->getKey(),
            ]);

            AuditLogger::record(
                entityType: 'ProposalPdfArtifact',
                entityId: $artifact->getKey(),
                action: 'proposal_pdf_artifact_generated',
                organizationId: $lockedVersion->organization_id,
                after: [
                    'proposal_version_id' => $lockedVersion->getKey(),
                    'checksum_sha256' => $rendered['checksum'],
                    'template_version' => self::TEMPLATE_VERSION,
                ],
            );

            return $artifact;
        });
    }

    /**
     * Rendering-only correction of an existing current primary artifact.
     * Approved OR Sent lifecycle, non-legacy, mandatory reason, requires an
     * existing current primary. Never changes lifecycle or any frozen
     * commercial field — only ever produces a new proposal_pdf_artifacts
     * row and supersession metadata on the old one.
     *
     * @throws LogicException For any precondition failure (wrong lifecycle, legacy, no existing
     *                        primary, blank reason, or a concurrent correction winning first) — these are
     *                        all "this action should not have been reachable/retryable as-is" cases, distinct
     *                        from a genuine rendering failure (which is recorded as a Failed row and
     *                        returned normally, matching generateIfMissing()).
     */
    public function correct(ProposalVersion $version, User $actor, string $correctionReason): ProposalPdfArtifact
    {
        $version = $version->fresh();

        $this->assertSameOrganization($version, $actor);
        $this->assertNotLegacy($version);

        if (blank($correctionReason)) {
            throw new LogicException('A correction reason is required to replace a Proposal PDF artifact.');
        }

        if (! in_array($version->lifecycle_status, [ProposalVersionLifecycle::Approved, ProposalVersionLifecycle::Sent], true)) {
            throw new LogicException(
                "ProposalVersion #{$version->getKey()} cannot correct its PDF: its lifecycle is {$version->lifecycle_status->value}, not approved or sent."
            );
        }

        $originalPrimary = $this->currentPrimary($version);

        if ($originalPrimary === null) {
            throw new LogicException(
                "ProposalVersion #{$version->getKey()} has no current successful PDF artifact to correct — use Generate instead."
            );
        }

        try {
            $rendered = $this->renderAndStore($version);
        } catch (Throwable $e) {
            return $this->recordFailure($version, $actor, $e->getMessage(), $correctionReason);
        }

        try {
            return DB::transaction(function () use ($version, $actor, $rendered, $correctionReason, $originalPrimary) {
                $lockedVersion = ProposalVersion::query()->whereKey($version->getKey())->lockForUpdate()->firstOrFail();

                $currentPrimary = $this->currentPrimary($lockedVersion);

                if ($currentPrimary === null || $currentPrimary->getKey() !== $originalPrimary->getKey()) {
                    throw new LogicException(
                        "ProposalVersion #{$lockedVersion->getKey()}'s current PDF artifact changed concurrently — reload and retry the correction."
                    );
                }

                // Update-then-insert order matters under primary_lock_key
                // (see the migration's own docblock): superseding the old
                // primary FIRST frees its claim on the generated-column
                // lock before the new row tries to claim it.
                $currentPrimary->forceFill(['superseded_at' => now()])->save();

                $newArtifact = ProposalPdfArtifact::create([
                    'proposal_version_id' => $lockedVersion->getKey(),
                    'status' => ProposalPdfArtifactStatus::Success,
                    'template_version' => self::TEMPLATE_VERSION,
                    'checksum_sha256' => $rendered['checksum'],
                    'storage_path' => $rendered['storagePath'],
                    'byte_size' => $rendered['byteSize'],
                    'generated_at' => now(),
                    'generated_by' => $actor->getKey(),
                    'correction_reason' => $correctionReason,
                ]);

                $currentPrimary->forceFill(['superseded_by_artifact_id' => $newArtifact->getKey()])->save();

                AuditLogger::record(
                    entityType: 'ProposalPdfArtifact',
                    entityId: $newArtifact->getKey(),
                    action: 'proposal_pdf_artifact_corrected',
                    organizationId: $lockedVersion->organization_id,
                    before: ['superseded_artifact_id' => $currentPrimary->getKey()],
                    after: [
                        'proposal_version_id' => $lockedVersion->getKey(),
                        'checksum_sha256' => $rendered['checksum'],
                        'correction_reason' => $correctionReason,
                    ],
                );

                return $newArtifact;
            });
        } catch (Throwable $e) {
            // DB swap failed (infrastructure error or a concurrent
            // correction winning first) — the old primary's truth must
            // remain valid, and the just-rendered replacement bytes are
            // now orphaned (nothing references them) and safe to remove.
            $this->deleteBytes($rendered['storagePath']);

            throw $e;
        }
    }

    /** The Version's current successful primary artifact, if any (proposal_pdf_artifacts.primary_lock_key claims exactly this Version's id). */
    public function currentPrimary(ProposalVersion $version): ?ProposalPdfArtifact
    {
        return ProposalPdfArtifact::query()
            ->where('proposal_version_id', $version->getKey())
            ->where('status', ProposalPdfArtifactStatus::Success)
            ->whereNull('superseded_at')
            ->first();
    }

    private function assertSameOrganization(ProposalVersion $version, User $actor): void
    {
        if ($version->organization_id !== $actor->organization_id) {
            throw new LogicException("User #{$actor->getKey()} cannot act on ProposalVersion #{$version->getKey()}: different organization.");
        }
    }

    private function assertNotLegacy(ProposalVersion $version): void
    {
        if ($version->is_legacy_backfill) {
            throw new LogicException(
                "ProposalVersion #{$version->getKey()} is a legacy backfilled Version — no final PDF is ever generated for it (no fabricated history)."
            );
        }
    }

    /**
     * Records a permanent Failed artifact row for a genuine rendering/
     * identity failure — never for a precondition that should have blocked
     * the call entirely (those throw LogicException instead, see the
     * public methods above). No checksum/storage_path/byte_size, since no
     * valid bytes exist.
     */
    private function recordFailure(ProposalVersion $version, User $actor, string $reason, ?string $correctionReason): ProposalPdfArtifact
    {
        $artifact = ProposalPdfArtifact::create([
            'proposal_version_id' => $version->getKey(),
            'status' => ProposalPdfArtifactStatus::Failed,
            'template_version' => self::TEMPLATE_VERSION,
            'generated_at' => now(),
            'generated_by' => $actor->getKey(),
            'failure_reason' => str($reason)->limit(2000)->value(),
            'correction_reason' => $correctionReason,
        ]);

        AuditLogger::record(
            entityType: 'ProposalPdfArtifact',
            entityId: $artifact->getKey(),
            action: 'proposal_pdf_artifact_generation_failed',
            organizationId: $version->organization_id,
            after: [
                'proposal_version_id' => $version->getKey(),
                'trigger' => $correctionReason === null ? 'generate' : 'correction',
                'failure_reason' => $artifact->failure_reason,
            ],
        );

        return $artifact;
    }

    /**
     * PHASE 1 (Section I): render + store replacement bytes with no
     * database lock held. Throws on any failure — callers convert that
     * into a permanent Failed artifact row. Verifies the stored bytes
     * before returning; deletes them again immediately if verification
     * fails, so no partial/corrupt file is ever left behind.
     *
     * @return array{checksum: string, byteSize: int, storagePath: string}
     */
    private function renderAndStore(ProposalVersion $version): array
    {
        $identity = app(OrganizationIdentityResolver::class)->resolveOrFail($version->organization_id);

        $bytes = $this->renderPdfBytes($version, $identity);
        $checksum = hash('sha256', $bytes);
        $storagePath = sprintf(
            'proposal-pdfs/%d/%d/%d/%s.pdf',
            $version->organization_id,
            $version->proposal_id,
            $version->getKey(),
            (string) str()->uuid()
        );

        $written = Storage::disk('local')->put($storagePath, $bytes);

        if (! $written) {
            throw new LogicException('Could not write the rendered PDF to private storage.');
        }

        $storedSize = Storage::disk('local')->size($storagePath);

        if ($storedSize !== strlen($bytes)) {
            $this->deleteBytes($storagePath);

            throw new LogicException('Stored PDF byte count did not match the rendered output — discarded.');
        }

        return ['checksum' => $checksum, 'byteSize' => $storedSize, 'storagePath' => $storagePath];
    }

    /**
     * Renders the frozen ProposalVersion snapshot into raw PDF bytes.
     * Reads ONLY from $version itself and $version->lines (with their tax
     * components) — never $version->proposal->prospect, which carries
     * live, possibly since-changed Prospect data. $version->proposal is
     * touched only for its immutable proposal_number.
     *
     * @param  array{legal_name: string, registered_address: string, gstin: string, phone: ?string, email: ?string, website: ?string, logo_path: ?string}  $identity
     */
    private function renderPdfBytes(ProposalVersion $version, array $identity): string
    {
        $version->loadMissing(['lines.taxComponents', 'proposal']);

        $money = fn (mixed $value) => number_format((float) $value, 2);

        $lines = $version->lines->map(fn ($line) => [
            'line_number' => $line->line_number,
            'item_name' => $line->item_name,
            'description' => $line->description,
            'hsn_sac' => $line->hsn_sac,
            'quantity' => rtrim(rtrim((string) $line->quantity, '0'), '.') ?: '0',
            'unit' => $line->unit,
            'unit_price' => $money($line->unit_price),
            'discount_amount' => $money($line->discount_amount ?? 0),
            'tax_amount' => $money($line->tax_amount ?? 0),
            'line_total' => $money($line->line_total),
            'tax_components' => $line->taxComponents->map(fn ($component) => [
                'type' => $component->component_type?->getLabel() ?? (string) $component->component_type,
                'rate' => rtrim(rtrim((string) $component->rate, '0'), '.') ?: '0',
                'amount' => $money($component->amount),
            ])->all(),
        ])->all();

        $logoDataUri = $this->logoDataUri($identity['logo_path']);

        return app('dompdf.wrapper')
            ->loadView('pdf.proposal-v1', [
                'identity' => $identity,
                'logoDataUri' => $logoDataUri,
                'proposalNumber' => $version->proposal->proposal_number,
                'versionNumber' => $version->version_number,
                'documentDate' => now()->format('d M Y'),
                'customer' => [
                    'name' => $version->customer_name_snapshot,
                    'gstin' => $version->customer_gstin_snapshot,
                    'billing_address' => $version->billing_address_snapshot,
                    'billing_state' => $version->billing_state_snapshot,
                    'place_of_supply' => $version->place_of_supply_snapshot,
                ],
                'scopeNotes' => $version->scope_notes,
                'paymentTerms' => $version->payment_terms,
                'validityTerms' => $version->validity_terms,
                'currencyCode' => $version->currency_code,
                'lines' => $lines,
                'subtotal' => $money($version->subtotal ?? 0),
                'totalDiscount' => $money($version->total_discount ?? 0),
                'taxTotal' => $money($version->tax_total ?? 0),
                'grandTotal' => $money($version->grand_total ?? 0),
            ])
            ->output();
    }

    /**
     * Optional letterhead logo — base64-embedded so dompdf never needs
     * filesystem/remote access at render time. Silently omitted (never
     * fails generation) if the configured path is blank or unreadable —
     * a logo is explicitly optional (locked Decision 2).
     */
    private function logoDataUri(?string $logoPath): ?string
    {
        if (blank($logoPath) || ! is_file($logoPath) || ! is_readable($logoPath)) {
            return null;
        }

        $mime = match (strtolower(pathinfo($logoPath, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            default => null,
        };

        if ($mime === null) {
            return null;
        }

        $contents = @file_get_contents($logoPath);

        if ($contents === false) {
            return null;
        }

        return "data:{$mime};base64,".base64_encode($contents);
    }

    private function deleteBytes(string $storagePath): void
    {
        try {
            Storage::disk('local')->delete($storagePath);
        } catch (Throwable) {
            // Best-effort cleanup only — never let a deletion failure mask
            // the original error that triggered this cleanup.
        }
    }
}
