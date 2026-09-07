<?php

namespace Tests\Feature;

use App\Models\Prospect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4A-2.1 locked Decision 1 + addendum: gstin/billing_address/
 * billing_state are new current master-data fields on Prospect, seeded
 * into a new Proposal's V1 commercial snapshot (a later sub-phase). Every
 * existing Prospect must start with all three NULL — never inferred from
 * the existing general-purpose `address`/`state` fields, and never
 * fabricated — since neither is proven to be the formal GST billing
 * address/state.
 */
class ProspectBillingDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_prospect_created_without_billing_fields_leaves_them_null(): void
    {
        $prospect = Prospect::factory()->create();

        $this->assertNull($prospect->gstin);
        $this->assertNull($prospect->billing_address);
        $this->assertNull($prospect->billing_state);
    }

    /**
     * The existing general-purpose address/state fields must never be
     * silently treated as the formal billing address/state — creating a
     * Prospect with only the general fields set must not cause the new
     * billing fields to auto-populate from them.
     */
    public function test_existing_general_address_and_state_are_not_copied_into_the_new_billing_fields(): void
    {
        $prospect = Prospect::factory()->create([
            'address' => '221B Baker Street',
            'state' => 'Tamil Nadu',
        ]);

        $this->assertSame('221B Baker Street', $prospect->address);
        $this->assertSame('Tamil Nadu', $prospect->state);
        $this->assertNull($prospect->billing_address);
        $this->assertNull($prospect->billing_state);
    }

    public function test_billing_fields_can_be_stored_normally_when_explicitly_supplied(): void
    {
        $prospect = Prospect::factory()->create([
            'gstin' => '33AAAAA0000A1Z5',
            'billing_address' => '42 GST Registered Office Road',
            'billing_state' => 'Tamil Nadu',
        ]);

        $fresh = $prospect->fresh();
        $this->assertSame('33AAAAA0000A1Z5', $fresh->gstin);
        $this->assertSame('42 GST Registered Office Road', $fresh->billing_address);
        $this->assertSame('Tamil Nadu', $fresh->billing_state);
    }
}
