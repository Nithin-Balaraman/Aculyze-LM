<?php

namespace App\Services;

use App\Enums\ProposalVersionLifecycle;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use App\Models\ProposalVersionLineTaxComponent;
use App\Models\User;
use App\Policies\ProposalVersionPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Phase 4A-2.5: the UI-independent server-side persistence path for
 * ordinary Draft commercial editing — the Filament Draft editor calls this
 * rather than writing to ProposalVersion/Line/TaxComponent directly, so
 * "validate inputs, persist raw data, invoke the calculator, persist
 * authoritative derived values, all-or-nothing" is owned in exactly one
 * place, never duplicated inside a Livewire page.
 *
 * Deliberately separate from ProposalVersionWorkflowService: that class
 * owns lifecycle TRANSITIONS (Draft -> Submitted -> Approved, etc.) under
 * its own pessimistic Proposal-row locking; this class owns repeatable,
 * in-place Draft CONTENT edits that never change lifecycle_status, gated
 * instead by an optimistic concurrency check on the Version's own
 * updated_at (locked Decision 6) — the two locking strategies are
 * unrelated and must not be conflated.
 *
 * Line/tax-component persistence strategy: delete-and-reinsert the
 * Draft's entire line set on every save, inside this one transaction. This
 * is safe specifically because (a) a Draft's lines are never historical —
 * nothing freezes them until the Version itself leaves Draft; (b) no
 * other table holds a stable foreign key into a Draft line's own id
 * (proposal_version_line_tax_components cascades on delete, and is
 * likewise fully replaced every save); (c) the replacement happens
 * atomically in this one transaction, so a mid-save failure leaves the
 * previous rows completely intact; and (d) once a Version actually
 * freezes (Submit), the rows that exist at that exact moment become the
 * Version's own permanent, independent history — nothing about that
 * moment depends on which specific row ids got there. Deleting through
 * each Eloquent model instance (never a raw mass-delete query) so the
 * 4A-2.1 immutability guards still fire on every single row, exactly as
 * they do for every other caller.
 */
class ProposalVersionDraftService
{
    /**
     * @param  array<string, mixed>  $snapshotAttributes  customer_name_snapshot/customer_gstin_snapshot/
     *                                                    billing_address_snapshot/billing_state_snapshot/place_of_supply_snapshot/scope_notes/payment_terms/
     *                                                    validity_terms/currency_code — Version snapshot fields only; never reads or writes Prospect.
     * @param  array<int, array{item_name: string, description?: ?string, hsn_sac?: ?string, quantity: mixed,
     *     unit?: ?string, unit_price: mixed, discount_type?: ?string, discount_value?: mixed,
     *     tax_components?: array<int, array{component_type: string, rate: mixed}>}>  $lines
     *
     * @throws LogicException If the Version is no longer Draft, the actor is not authorized, the
     *                        optimistic concurrency token is stale, or the resulting financial data violates the
     *                        locked calculator rules (invalid discount/tax data).
     */
    public function saveDraft(
        ProposalVersion $version,
        User $actor,
        string $expectedUpdatedAt,
        array $snapshotAttributes,
        array $lines,
    ): ProposalVersion {
        return DB::transaction(function () use ($version, $actor, $expectedUpdatedAt, $snapshotAttributes, $lines) {
            $locked = ProposalVersion::query()->whereKey($version->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->lifecycle_status !== ProposalVersionLifecycle::Draft) {
                throw new LogicException(
                    "ProposalVersion #{$locked->getKey()} is no longer Draft ({$locked->lifecycle_status->value}) — it cannot be edited."
                );
            }

            if (! app(ProposalVersionPolicy::class)->edit($actor, $locked)) {
                throw new LogicException("You are not authorized to edit ProposalVersion #{$locked->getKey()}'s commercial Draft.");
            }

            if ($locked->updated_at === null || ! $locked->updated_at->equalTo(Carbon::parse($expectedUpdatedAt))) {
                throw new LogicException('This Draft was changed after you opened it. Reload the latest version before saving.');
            }

            $locked->forceFill($snapshotAttributes);

            foreach ($locked->lines as $existingLine) {
                $existingLine->delete();
            }

            foreach (array_values($lines) as $index => $lineData) {
                $line = ProposalVersionLine::create([
                    'proposal_version_id' => $locked->getKey(),
                    'line_number' => $index + 1,
                    'item_name' => $lineData['item_name'],
                    'description' => $lineData['description'] ?? null,
                    'hsn_sac' => $lineData['hsn_sac'] ?? null,
                    'quantity' => $lineData['quantity'],
                    'unit' => $lineData['unit'] ?? null,
                    'unit_price' => $lineData['unit_price'],
                    'discount_type' => $lineData['discount_type'] ?? null,
                    'discount_value' => $lineData['discount_value'] ?? null,
                ]);

                foreach ($lineData['tax_components'] ?? [] as $componentData) {
                    ProposalVersionLineTaxComponent::create([
                        'proposal_version_line_id' => $line->getKey(),
                        'component_type' => $componentData['component_type'],
                        'rate' => $componentData['rate'],
                        // Placeholder — ProposalVersionCalculator::recalculate()
                        // below overwrites this with the authoritative amount
                        // before this transaction ever commits.
                        'amount' => 0,
                    ]);
                }
            }

            app(ProposalVersionCalculator::class)->recalculate($locked);

            $locked->save();

            return $locked->fresh(['lines.taxComponents']);
        });
    }
}
