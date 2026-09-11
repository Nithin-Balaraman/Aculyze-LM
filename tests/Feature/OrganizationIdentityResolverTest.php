<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Support\Organization\OrganizationIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Phase 4A-3.2, Section E (locked Decision 2): identity resolution order —
 * organizations.settings['identity'] first, config/aculyze.php fallback
 * second, mandatory-field enforcement from either source.
 */
class OrganizationIdentityResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['aculyze.organization_identity' => [
            'legal_name' => 'Config Fallback Legal Name',
            'registered_address' => 'Config Fallback Address',
            'gstin' => 'CONFIGGSTIN123',
            'phone' => null,
            'email' => null,
            'website' => null,
            'logo_path' => null,
        ]]);
    }

    public function test_organization_settings_identity_overrides_config_fallback(): void
    {
        $organization = Organization::factory()->create([
            'settings' => ['identity' => [
                'legal_name' => 'Org-Specific Legal Name',
                'registered_address' => 'Org-Specific Address',
                'gstin' => 'ORGGSTIN999',
            ]],
        ]);

        $identity = app(OrganizationIdentityResolver::class)->resolveOrFail($organization->id);

        $this->assertSame('Org-Specific Legal Name', $identity['legal_name']);
        $this->assertSame('Org-Specific Address', $identity['registered_address']);
        $this->assertSame('ORGGSTIN999', $identity['gstin']);
    }

    public function test_config_fallback_is_used_when_organization_has_no_identity_settings(): void
    {
        $organization = Organization::factory()->create(['settings' => null]);

        $identity = app(OrganizationIdentityResolver::class)->resolveOrFail($organization->id);

        $this->assertSame('Config Fallback Legal Name', $identity['legal_name']);
        $this->assertSame('Config Fallback Address', $identity['registered_address']);
        $this->assertSame('CONFIGGSTIN123', $identity['gstin']);
    }

    public function test_fields_mix_field_by_field_across_both_sources(): void
    {
        $organization = Organization::factory()->create([
            // Only gstin is overridden — legal_name/registered_address fall
            // through to config individually, not as an all-or-nothing unit.
            'settings' => ['identity' => ['gstin' => 'ONLY-GSTIN-SET']],
        ]);

        $identity = app(OrganizationIdentityResolver::class)->resolveOrFail($organization->id);

        $this->assertSame('Config Fallback Legal Name', $identity['legal_name']);
        $this->assertSame('Config Fallback Address', $identity['registered_address']);
        $this->assertSame('ONLY-GSTIN-SET', $identity['gstin']);
    }

    public function test_missing_legal_name_from_both_sources_blocks(): void
    {
        config(['aculyze.organization_identity.legal_name' => null]);
        $organization = Organization::factory()->create(['settings' => null]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('legal_name');

        app(OrganizationIdentityResolver::class)->resolveOrFail($organization->id);
    }

    public function test_missing_registered_address_from_both_sources_blocks(): void
    {
        config(['aculyze.organization_identity.registered_address' => null]);
        $organization = Organization::factory()->create(['settings' => null]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('registered_address');

        app(OrganizationIdentityResolver::class)->resolveOrFail($organization->id);
    }

    public function test_missing_gstin_from_both_sources_blocks(): void
    {
        config(['aculyze.organization_identity.gstin' => null]);
        $organization = Organization::factory()->create(['settings' => null]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('gstin');

        app(OrganizationIdentityResolver::class)->resolveOrFail($organization->id);
    }

    public function test_optional_fields_default_to_null_never_fabricated(): void
    {
        $organization = Organization::factory()->create(['settings' => null]);

        $identity = app(OrganizationIdentityResolver::class)->resolveOrFail($organization->id);

        $this->assertNull($identity['phone']);
        $this->assertNull($identity['email']);
        $this->assertNull($identity['website']);
        $this->assertNull($identity['logo_path']);
    }

    public function test_whitespace_only_value_is_treated_as_blank_not_a_real_value(): void
    {
        $organization = Organization::factory()->create([
            'settings' => ['identity' => ['legal_name' => '   ']],
        ]);

        $identity = app(OrganizationIdentityResolver::class)->resolveOrFail($organization->id);

        // Falls through to config, since the organization's own value was blank.
        $this->assertSame('Config Fallback Legal Name', $identity['legal_name']);
    }
}
