<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Organization;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantContextMissingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1: App\Models\Scopes\OrganizationScope fails closed when no tenant
 * context is set, and App\Support\Tenancy\Tenancy::runAs()/
 * withoutScopeForSystemTask() always restore whatever context existed
 * before them — including when their callback throws, and at arbitrary
 * nesting depth.
 */
class TenantContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_querying_an_organization_owned_model_without_tenant_context_fails_closed(): void
    {
        TenantContext::forget();

        $this->expectException(TenantContextMissingException::class);

        Lead::count();
    }

    public function test_tenant_context_is_restored_after_run_as_completes(): void
    {
        $organization = Organization::factory()->create();
        TenantContext::forget();

        Tenancy::runAs($organization->id, function () use ($organization) {
            $this->assertSame($organization->id, TenantContext::current());
        });

        $this->assertNull(TenantContext::current());
    }

    public function test_tenant_context_is_restored_even_when_the_callback_throws(): void
    {
        $organization = Organization::factory()->create();
        TenantContext::set(999999);

        try {
            Tenancy::runAs($organization->id, function () {
                throw new \RuntimeException('deliberate failure');
            });
            $this->fail('Expected exception was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('deliberate failure', $e->getMessage());
        }

        $this->assertSame(999999, TenantContext::current());
    }

    public function test_nested_run_as_restores_the_correct_intermediate_context_at_each_level(): void
    {
        $outer = Organization::factory()->create();
        $middle = Organization::factory()->create();
        $inner = Organization::factory()->create();

        TenantContext::forget();

        Tenancy::runAs($outer->id, function () use ($middle, $inner, $outer) {
            $this->assertSame($outer->id, TenantContext::current());

            Tenancy::runAs($middle->id, function () use ($inner, $middle) {
                $this->assertSame($middle->id, TenantContext::current());

                Tenancy::runAs($inner->id, function () use ($inner) {
                    $this->assertSame($inner->id, TenantContext::current());
                });

                $this->assertSame($middle->id, TenantContext::current());
            });

            $this->assertSame($outer->id, TenantContext::current());
        });

        $this->assertNull(TenantContext::current());
    }

    public function test_withoutScopeForSystemTask_bypasses_the_scope_and_restores_context_after(): void
    {
        Organization::factory()->create();
        Organization::factory()->create();

        TenantContext::forget();

        $count = Tenancy::withoutScopeForSystemTask('count leads across all organizations', fn () => Lead::count());

        $this->assertIsInt($count);

        // Fail-closed resumes immediately once the bypass ends.
        $this->expectException(TenantContextMissingException::class);
        Lead::count();
    }

    public function test_withoutScopeForSystemTask_restores_bypass_state_even_when_callback_throws(): void
    {
        $organization = Organization::factory()->create();
        TenantContext::set($organization->id);

        try {
            Tenancy::withoutScopeForSystemTask('deliberate failure', function () {
                throw new \RuntimeException('boom');
            });
            $this->fail('Expected exception was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        // Bypass must not still be active — a normal scoped query should
        // now correctly reflect the (restored) tenant context, not see
        // every organization's data. organization_id auto-derives from
        // prospect_id (see Lead::inheritedOrganizationId()), which itself
        // was created within this same organization.
        $owner = \App\Models\User::factory()->create(['organization_id' => $organization->id]);

        Lead::create([
            'prospect_id' => \App\Models\Prospect::factory()->create(['organization_id' => $organization->id, 'assigned_to' => $owner->id, 'created_by' => $owner->id])->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'stage' => 'requirement_collection',
            'temperature' => 'warm',
        ]);

        $this->assertSame(1, Lead::count());
    }
}
