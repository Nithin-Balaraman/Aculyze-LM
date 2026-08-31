<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Models\CallRecord;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2 item #14 verification: a Prospect ("Company") can already have
 * multiple Leads (Prospect::leads() is hasMany, and leads.call_record_id —
 * not prospect_id — is the unique constraint), one per Call Record that
 * routes to Requirement Identified. Phase 2 doesn't change this mechanism;
 * this test hardens/confirms it under the new organization/hierarchy
 * scoping rather than building new infrastructure.
 */
class MultipleLeadsPerCompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_single_prospect_can_have_multiple_independent_leads(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

            CallRecord::create([
                'prospect_id' => $prospect->id,
                'user_id' => $user->id,
                'called_at' => now(),
                'outcome' => CallOutcome::RequirementIdentified,
                'notes' => 'Requirement confirmed on call.',
            ]);
            CallRecord::create([
                'prospect_id' => $prospect->id,
                'user_id' => $user->id,
                'called_at' => now(),
                'outcome' => CallOutcome::RequirementIdentified,
                'notes' => 'Requirement confirmed on call.',
            ]);

            $this->assertSame(2, Lead::query()->where('prospect_id', $prospect->id)->count());
            $this->assertSame(2, $prospect->leads()->count());
        });
    }

    public function test_both_leads_are_independently_workable_and_visible(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

            $callA = CallRecord::create(['prospect_id' => $prospect->id, 'user_id' => $user->id, 'called_at' => now(), 'outcome' => CallOutcome::RequirementIdentified, 'notes' => 'Requirement confirmed on call.']);
            $callB = CallRecord::create(['prospect_id' => $prospect->id, 'user_id' => $user->id, 'called_at' => now(), 'outcome' => CallOutcome::RequirementIdentified, 'notes' => 'Requirement confirmed on call.']);

            $leadA = Lead::query()->where('call_record_id', $callA->id)->firstOrFail();
            $leadB = Lead::query()->where('call_record_id', $callB->id)->firstOrFail();

            $this->assertNotSame($leadA->id, $leadB->id);

            $leadA->update(['status' => \App\Enums\LeadStatus::ProposalRequired]);

            $this->assertSame(\App\Enums\LeadStatus::RequirementCollection, $leadB->fresh()->status);
            $this->assertTrue(Lead::query()->visibleTo($user)->whereKey($leadA->id)->exists());
            $this->assertTrue(Lead::query()->visibleTo($user)->whereKey($leadB->id)->exists());
        });
    }
}
