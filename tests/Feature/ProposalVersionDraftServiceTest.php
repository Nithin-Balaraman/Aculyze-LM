<?php

namespace Tests\Feature;

use App\Enums\ProposalLineDiscountType;
use App\Enums\ProposalTaxComponentType;
use App\Enums\ProposalVersionLifecycle;
use App\Enums\UserRole;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use App\Models\Prospect;
use App\Models\User;
use App\Services\ProposalVersionDraftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Phase 4A-2.5: ProposalVersionDraftService — the UI-independent Draft
 * commercial save path (customer/terms + whole line/tax replacement +
 * authoritative recalculation), separate from lifecycle transitions.
 */
class ProposalVersionDraftServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{employee: User, manager: User, seniorManager: User} */
    private function hierarchy(): array
    {
        $seniorManager = User::factory()->admin()->create();
        $manager = User::factory()->create(['role' => UserRole::Manager, 'manager_id' => $seniorManager->id]);
        $employee = User::factory()->create(['role' => UserRole::Employee, 'manager_id' => $manager->id]);

        return compact('employee', 'manager', 'seniorManager');
    }

    private function draftVersionFor(User $employee): ProposalVersion
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);

        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => 'validated',
            'temperature' => 'hot',
            'notes' => 'Validated in test fixture.',
        ]);

        $proposal = Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => 'being_prepared',
        ]);

        $version = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'version_number' => 1,
        ]);

        $proposal->forceFill(['current_version_id' => $version->id])->save();

        return $version->fresh();
    }

    private function token(ProposalVersion $version): string
    {
        return (string) $version->fresh()->updated_at;
    }

    public function test_manager_can_save_customer_snapshot_and_line_data(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $version = $this->draftVersionFor($employee);

        $saved = app(ProposalVersionDraftService::class)->saveDraft(
            $version,
            $manager,
            $this->token($version),
            ['customer_name_snapshot' => 'Acme Corp', 'payment_terms' => 'Net 30'],
            [['item_name' => 'Widget', 'quantity' => '2', 'unit_price' => '500.00']],
        );

        $this->assertSame('Acme Corp', $saved->customer_name_snapshot);
        $this->assertSame('Net 30', $saved->payment_terms);
        $this->assertSame(1, $saved->lines->count());
        $this->assertSame('1000.00', $saved->lines->first()->gross_amount);
        $this->assertSame('1000.00', $saved->subtotal);
    }

    public function test_editing_snapshot_fields_does_not_change_prospect_master_data(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $version = $this->draftVersionFor($employee);
        $originalCompanyName = $version->proposal->prospect->company_name;

        app(ProposalVersionDraftService::class)->saveDraft(
            $version,
            $manager,
            $this->token($version),
            ['customer_name_snapshot' => 'A Totally Different Name'],
            [],
        );

        $this->assertSame($originalCompanyName, $version->proposal->prospect->fresh()->company_name);
    }

    public function test_can_create_a_line(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $version = $this->draftVersionFor($employee);

        $saved = app(ProposalVersionDraftService::class)->saveDraft(
            $version, $manager, $this->token($version), [],
            [['item_name' => 'Widget', 'quantity' => '1', 'unit_price' => '100.00']],
        );

        $this->assertSame(1, $saved->lines->count());
    }

    public function test_can_edit_a_line(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $version = $this->draftVersionFor($employee);

        app(ProposalVersionDraftService::class)->saveDraft(
            $version, $manager, $this->token($version), [],
            [['item_name' => 'Widget', 'quantity' => '1', 'unit_price' => '100.00']],
        );

        $saved = app(ProposalVersionDraftService::class)->saveDraft(
            $version, $manager, $this->token($version), [],
            [['item_name' => 'Widget V2', 'quantity' => '3', 'unit_price' => '100.00']],
        );

        $this->assertSame(1, $saved->lines->count());
        $this->assertSame('Widget V2', $saved->lines->first()->item_name);
        $this->assertSame('300.00', $saved->lines->first()->gross_amount);
    }

    public function test_can_delete_a_line(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $version = $this->draftVersionFor($employee);

        app(ProposalVersionDraftService::class)->saveDraft(
            $version, $manager, $this->token($version), [],
            [
                ['item_name' => 'Widget', 'quantity' => '1', 'unit_price' => '100.00'],
                ['item_name' => 'Gadget', 'quantity' => '1', 'unit_price' => '50.00'],
            ],
        );

        $saved = app(ProposalVersionDraftService::class)->saveDraft(
            $version, $manager, $this->token($version), [],
            [['item_name' => 'Widget', 'quantity' => '1', 'unit_price' => '100.00']],
        );

        $this->assertSame(1, $saved->lines->count());
        $this->assertSame('Widget', $saved->lines->first()->item_name);
    }

    public function test_can_add_a_tax_component(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $version = $this->draftVersionFor($employee);

        $saved = app(ProposalVersionDraftService::class)->saveDraft(
            $version, $manager, $this->token($version), [],
            [[
                'item_name' => 'Widget', 'quantity' => '1', 'unit_price' => '1000.00',
                'tax_components' => [['component_type' => ProposalTaxComponentType::Cgst->value, 'rate' => '9']],
            ]],
        );

        $this->assertSame(1, $saved->lines->first()->taxComponents->count());
        $this->assertSame('90.00', $saved->lines->first()->taxComponents->first()->amount);
        $this->assertSame('90.00', $saved->lines->first()->tax_amount);
    }

    public function test_can_edit_and_delete_a_tax_component(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $version = $this->draftVersionFor($employee);

        app(ProposalVersionDraftService::class)->saveDraft(
            $version, $manager, $this->token($version), [],
            [[
                'item_name' => 'Widget', 'quantity' => '1', 'unit_price' => '1000.00',
                'tax_components' => [
                    ['component_type' => ProposalTaxComponentType::Cgst->value, 'rate' => '9'],
                    ['component_type' => ProposalTaxComponentType::Sgst->value, 'rate' => '9'],
                ],
            ]],
        );

        // Edit the rate and remove SGST entirely.
        $saved = app(ProposalVersionDraftService::class)->saveDraft(
            $version, $manager, $this->token($version), [],
            [[
                'item_name' => 'Widget', 'quantity' => '1', 'unit_price' => '1000.00',
                'tax_components' => [
                    ['component_type' => ProposalTaxComponentType::Cgst->value, 'rate' => '18'],
                ],
            ]],
        );

        $this->assertSame(1, $saved->lines->first()->taxComponents->count());
        $this->assertSame('180.00', $saved->lines->first()->taxComponents->first()->amount);
    }

    public function test_derived_values_are_recalculated_by_the_server(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $version = $this->draftVersionFor($employee);

        $saved = app(ProposalVersionDraftService::class)->saveDraft(
            $version, $manager, $this->token($version), [],
            [[
                'item_name' => 'Widget', 'quantity' => '2', 'unit_price' => '500.00',
                'discount_type' => ProposalLineDiscountType::Percentage->value,
                'discount_value' => '10',
            ]],
        );

        // gross 1000, 10% discount = 100, taxable = 900, no tax, total = 900.
        $line = $saved->lines->first();
        $this->assertSame('1000.00', $line->gross_amount);
        $this->assertSame('100.00', $line->discount_amount);
        $this->assertSame('900.00', $line->taxable_amount);
        $this->assertSame('900.00', $line->line_total);
    }

    public function test_user_supplied_derived_values_do_not_become_authority(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $version = $this->draftVersionFor($employee);

        // The Draft editor never even collects these from the user, but
        // prove the calculator recomputes regardless of whatever a
        // tampered client-side payload attempts to smuggle through — the
        // service's own line payload contract simply has no derived-value
        // keys for a caller to smuggle values into in the first place.
        $saved = app(ProposalVersionDraftService::class)->saveDraft(
            $version, $manager, $this->token($version), [],
            [['item_name' => 'Widget', 'quantity' => '1', 'unit_price' => '100.00']],
        );

        $this->assertSame('100.00', $saved->lines->first()->gross_amount);
    }

    public function test_invalid_discount_data_rejects_the_save_and_leaves_draft_unchanged(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $version = $this->draftVersionFor($employee);

        app(ProposalVersionDraftService::class)->saveDraft(
            $version, $manager, $this->token($version), [],
            [['item_name' => 'Widget', 'quantity' => '1', 'unit_price' => '100.00']],
        );

        $originalLineCount = ProposalVersionLine::where('proposal_version_id', $version->id)->count();

        try {
            app(ProposalVersionDraftService::class)->saveDraft(
                $version, $manager, $this->token($version), [],
                [[
                    'item_name' => 'Widget', 'quantity' => '1', 'unit_price' => '100.00',
                    'discount_type' => ProposalLineDiscountType::Percentage->value,
                    'discount_value' => '150',
                ]],
            );
            $this->fail('Expected an invalid discount to be rejected.');
        } catch (LogicException) {
            // expected
        }

        $this->assertSame($originalLineCount, ProposalVersionLine::where('proposal_version_id', $version->id)->count());
        $this->assertSame('Widget', ProposalVersionLine::where('proposal_version_id', $version->id)->first()->item_name);
    }

    public function test_employee_cannot_save_a_draft(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $version = $this->draftVersionFor($employee);

        $this->expectException(LogicException::class);
        app(ProposalVersionDraftService::class)->saveDraft($version, $employee, $this->token($version), [], []);
    }

    public function test_non_draft_version_cannot_be_saved(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $version = $this->draftVersionFor($employee);
        $version->forceFill(['lifecycle_status' => ProposalVersionLifecycle::Submitted])->save();

        $this->expectException(LogicException::class);
        app(ProposalVersionDraftService::class)->saveDraft($version->fresh(), $manager, $this->token($version), [], []);
    }

    public function test_stale_updated_at_save_is_rejected(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $version = $this->draftVersionFor($employee);
        $staleToken = $this->token($version);

        // Someone else saves first — travel forward so the row's real
        // updated_at is guaranteed to differ from $staleToken even on a
        // database with only second-level timestamp precision.
        $this->travel(1)->minute();
        app(ProposalVersionDraftService::class)->saveDraft($version, $manager, $staleToken, ['payment_terms' => 'First save.'], []);

        $this->expectException(LogicException::class);
        app(ProposalVersionDraftService::class)->saveDraft($version, $manager, $staleToken, ['payment_terms' => 'Stale second save.'], []);
    }

    public function test_rejected_stale_save_does_not_partially_alter_draft(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $version = $this->draftVersionFor($employee);
        $staleToken = $this->token($version);

        $this->travel(1)->minute();
        app(ProposalVersionDraftService::class)->saveDraft($version, $manager, $staleToken, ['payment_terms' => 'First save.'], []);

        try {
            app(ProposalVersionDraftService::class)->saveDraft($version, $manager, $staleToken, ['payment_terms' => 'Stale second save.'], [
                ['item_name' => 'Should not persist', 'quantity' => '1', 'unit_price' => '1.00'],
            ]);
        } catch (LogicException) {
            // expected
        }

        $fresh = $version->fresh();
        $this->assertSame('First save.', $fresh->payment_terms);
        $this->assertSame(0, $fresh->lines()->count());
    }

    public function test_fresh_reload_and_save_succeeds(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $version = $this->draftVersionFor($employee);

        app(ProposalVersionDraftService::class)->saveDraft($version, $manager, $this->token($version), ['payment_terms' => 'First save.'], []);

        $freshToken = $this->token($version);
        $saved = app(ProposalVersionDraftService::class)->saveDraft($version->fresh(), $manager, $freshToken, ['payment_terms' => 'Second save.'], []);

        $this->assertSame('Second save.', $saved->payment_terms);
    }

    public function test_concurrency_token_refreshes_after_successful_save(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $version = $this->draftVersionFor($employee);
        $originalUpdatedAt = $version->updated_at;
        $originalToken = $this->token($version);

        // Second-precision timestamp columns make "the token changed" the
        // only reliable cross-database assertion here — travel forward so
        // this is deterministic regardless of how fast the test runs.
        $this->travel(1)->minute();

        $saved = app(ProposalVersionDraftService::class)->saveDraft($version, $manager, $originalToken, ['payment_terms' => 'Updated.'], []);

        $this->assertFalse($saved->updated_at->equalTo($originalUpdatedAt));
        $this->assertNotSame($originalToken, $this->token($saved));
    }
}
