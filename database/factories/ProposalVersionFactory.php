<?php

namespace Database\Factories;

use App\Enums\ProposalVersionLifecycle;
use App\Models\ProposalVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Mirrors DemoFactory's convention: organization_id is deliberately NOT set
 * here (inherited from the given proposal_id via
 * ProposalVersion::inheritedOrganizationId()) — callers must supply
 * 'proposal_id' explicitly, belonging to the current tenant.
 *
 * @extends Factory<ProposalVersion>
 */
class ProposalVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'version_number' => 1,
            'lifecycle_status' => ProposalVersionLifecycle::Draft,
            'is_legacy_backfill' => false,
            'payment_terms' => fake()->sentence(),
            'validity_terms' => fake()->sentence(),
            'scope_notes' => fake()->paragraph(),
            'subtotal' => 1000,
            'total_discount' => 0,
            'tax_total' => 180,
            'grand_total' => 1180,
            'currency_code' => 'INR',
        ];
    }

    public function legacyBackfill(): self
    {
        return $this->state(fn () => [
            'is_legacy_backfill' => true,
        ]);
    }
}
