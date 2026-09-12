<?php

namespace Tests\Feature;

use App\Enums\ProposalOutcome;
use App\Enums\ProposalStage;
use App\Enums\ProposalVersionLifecycle;
use App\Enums\UserRole;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalClientResponse;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use App\Models\Prospect;
use App\Models\User;
use App\Services\ProposalClientResponseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4A-3.4, Sections H/I/J: Revision Requested — the exact Sent Version
 * responded to may be non-current (locked Decision 11); no active Draft
 * creates one via cloning, an existing Draft is reused (never a second
 * created); an Employee's authority to trigger this comes from
 * recordClientResponse, never Manager-only createRevision(); a legacy
 * source's totals are never carried over as authoritative.
 */
class ProposalClientResponseRevisionRequestedTest extends TestCase
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

    /** @return array{0: Proposal, 1: ProposalVersion} */
    private function sentProposalWithLine(User $employee, bool $legacy = false): array
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $lead = Lead::create([
            'prospect_id' => $prospect->id, 'assigned_to' => $employee->id, 'created_by' => $employee->id,
            'stage' => 'validated', 'temperature' => 'hot', 'notes' => 'Fixture.',
        ]);
        $proposal = Proposal::create([
            'lead_id' => $lead->id, 'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id, 'created_by' => $employee->id, 'stage' => 'sent',
        ]);

        if ($legacy) {
            $version = ProposalVersion::factory()->create([
                'proposal_id' => $proposal->id,
                'lifecycle_status' => ProposalVersionLifecycle::Sent,
                'is_legacy_backfill' => true,
                'grand_total' => 9999,
                'customer_name_snapshot' => null,
            ]);
        } else {
            $version = ProposalVersion::factory()->create([
                'proposal_id' => $proposal->id,
                'lifecycle_status' => ProposalVersionLifecycle::Draft,
                'customer_name_snapshot' => 'Acme Corp',
            ]);
            ProposalVersionLine::create([
                'proposal_version_id' => $version->id, 'line_number' => 1,
                'item_name' => 'Widget', 'quantity' => 2, 'unit_price' => 500,
            ]);
            $version->forceFill(['lifecycle_status' => ProposalVersionLifecycle::Sent, 'grand_total' => 1000])->save();
        }

        $proposal->forceFill(['current_version_id' => $version->id])->save();

        return [$proposal->fresh(), $version->fresh(['proposal'])];
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    public function test_non_current_sent_version_response_is_allowed(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $v1] = $this->sentProposalWithLine($employee);

        $v2 = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id, 'version_number' => 2,
            'lifecycle_status' => ProposalVersionLifecycle::Draft,
        ]);
        $proposal->forceFill(['current_version_id' => $v2->id])->save();

        $response = app(ProposalClientResponseService::class)->recordRevisionRequested($v1->fresh(), $employee, 'Change pricing', null, $this->key());

        $this->assertSame($v1->getKey(), $response->proposal_version_id);
    }

    public function test_no_active_draft_creates_a_new_draft(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposalWithLine($employee);

        $response = app(ProposalClientResponseService::class)->recordRevisionRequested($version->fresh(), $employee, 'Change pricing', null, $this->key());

        $newDraft = ProposalVersion::find($response->resulting_draft_version_id);
        $this->assertNotNull($newDraft);
        $this->assertSame(ProposalVersionLifecycle::Draft, $newDraft->lifecycle_status);
        $this->assertNotEquals($version->getKey(), $newDraft->getKey());
    }

    public function test_current_version_id_moves_to_new_draft(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposalWithLine($employee);

        $response = app(ProposalClientResponseService::class)->recordRevisionRequested($version->fresh(), $employee, 'Change pricing', null, $this->key());

        $this->assertSame($response->resulting_draft_version_id, $proposal->fresh()->current_version_id);
    }

    public function test_resulting_draft_version_id_links_the_new_draft(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposalWithLine($employee);

        $response = app(ProposalClientResponseService::class)->recordRevisionRequested($version->fresh(), $employee, 'Change pricing', null, $this->key());

        $this->assertNotNull($response->resulting_draft_version_id);
    }

    public function test_existing_current_draft_is_reused_response_still_recorded(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $v1] = $this->sentProposalWithLine($employee);

        $v2 = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id, 'version_number' => 2,
            'lifecycle_status' => ProposalVersionLifecycle::Draft,
        ]);
        $proposal->forceFill(['current_version_id' => $v2->id])->save();

        $response = app(ProposalClientResponseService::class)->recordRevisionRequested($v1->fresh(), $employee, 'Change pricing', null, $this->key());

        $this->assertSame($v2->getKey(), $response->resulting_draft_version_id);
    }

    public function test_no_second_draft_created_when_one_already_exists(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $v1] = $this->sentProposalWithLine($employee);

        $v2 = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id, 'version_number' => 2,
            'lifecycle_status' => ProposalVersionLifecycle::Draft,
        ]);
        $proposal->forceFill(['current_version_id' => $v2->id])->save();

        app(ProposalClientResponseService::class)->recordRevisionRequested($v1->fresh(), $employee, 'Change pricing', null, $this->key());

        $this->assertSame(1, ProposalVersion::query()->where('lifecycle_status', ProposalVersionLifecycle::Draft)->count());
    }

    public function test_employee_recorder_succeeds_without_manager_only_create_revision_policy(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [, $version] = $this->sentProposalWithLine($employee);

        // Directly proves this does NOT depend on ProposalVersionPolicy::
        // createRevision() (which would reject an Employee outright).
        $this->assertFalse(app(\App\Policies\ProposalVersionPolicy::class)->createRevision($employee, $version));

        $response = app(ProposalClientResponseService::class)->recordRevisionRequested($version->fresh(), $employee, 'Change pricing', null, $this->key());

        $this->assertNotNull($response->resulting_draft_version_id);
    }

    public function test_proposal_stage_becomes_being_prepared(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposalWithLine($employee);

        app(ProposalClientResponseService::class)->recordRevisionRequested($version->fresh(), $employee, 'Change pricing', null, $this->key());

        $this->assertSame(ProposalStage::BeingPrepared, $proposal->fresh()->stage);
    }

    public function test_hold_outcome_is_cleared(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposalWithLine($employee);
        $proposal->forceFill(['outcome' => ProposalOutcome::Hold, 'notes' => 'On hold.'])->save();

        app(ProposalClientResponseService::class)->recordRevisionRequested($version->fresh(), $employee, 'Change pricing', null, $this->key());

        $this->assertNull($proposal->fresh()->outcome);
    }

    public function test_legacy_sent_source_clone_zeroes_unsupported_totals_and_has_no_lines(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposalWithLine($employee, legacy: true);

        $response = app(ProposalClientResponseService::class)->recordRevisionRequested($version->fresh(), $employee, 'Rebuild pricing', null, $this->key());

        $newDraft = ProposalVersion::find($response->resulting_draft_version_id);
        $this->assertNull($newDraft->grand_total);
        $this->assertNull($newDraft->subtotal);
        $this->assertSame(0, $newDraft->lines()->count());
        $this->assertFalse($newDraft->is_legacy_backfill);
    }

    public function test_source_sent_history_unchanged(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposalWithLine($employee);
        $originalGrandTotal = $version->grand_total;

        app(ProposalClientResponseService::class)->recordRevisionRequested($version->fresh(), $employee, 'Change pricing', null, $this->key());

        $freshSource = $version->fresh();
        $this->assertSame(ProposalVersionLifecycle::Sent, $freshSource->lifecycle_status);
        $this->assertSame($originalGrandTotal, $freshSource->grand_total);
        $this->assertSame(1, $freshSource->lines()->count());
    }
}
