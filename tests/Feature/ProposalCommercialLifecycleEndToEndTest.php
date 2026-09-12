<?php

namespace Tests\Feature;

use App\Enums\ProposalBillingHandoffStatus;
use App\Enums\ProposalOutcome;
use App\Enums\ProposalStage;
use App\Enums\ProposalVersionLifecycle;
use App\Enums\UserRole;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalBillingHandoff;
use App\Models\ProposalPdfArtifact;
use App\Models\ProposalSend;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use App\Models\Prospect;
use App\Models\User;
use App\Services\ProposalClientResponseService;
use App\Services\ProposalPdfArtifactService;
use App\Services\ProposalReleaseService;
use App\Services\ProposalSendService;
use App\Services\ProposalVersionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4A-3.6, Section F: 8 cohesive end-to-end business regression
 * scenarios chaining the REAL services together exactly as a genuine user
 * session would — Draft through a terminal outcome (or a controlled detour
 * back to Draft), one scenario per method. Every individual step here is
 * already covered in isolation, exhaustively, by the dedicated per-service
 * test files (ProposalVersionWorkflowService*Test, ProposalSendTest,
 * ProposalClientResponse*Test, ManageCommercialVersion*ActionsTest, etc.) —
 * this file's job is only to confirm the FULL chains themselves hold
 * together end to end, since no single existing file exercises all of them
 * strung together in one Proposal's lifecycle.
 */
class ProposalCommercialLifecycleEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config(['aculyze.organization_identity' => [
            'legal_name' => 'Aculyze Solutions Pvt Ltd',
            'registered_address' => '123 Business Park, Bengaluru',
            'gstin' => '29ABCDE1234F1Z5',
        ]]);
    }

    /** @return array{employee: User, manager: User, seniorManager: User} */
    private function hierarchy(): array
    {
        $seniorManager = User::factory()->admin()->create();
        $manager = User::factory()->create(['role' => UserRole::Manager, 'manager_id' => $seniorManager->id]);
        $employee = User::factory()->create(['role' => UserRole::Employee, 'manager_id' => $manager->id]);

        return compact('employee', 'manager', 'seniorManager');
    }

    /** A brand-new Proposal with its V1 Draft, exactly as ProposalCreationService leaves it. */
    private function draftProposal(User $employee): Proposal
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id, 'email' => 'client@example.com']);
        $lead = Lead::create([
            'prospect_id' => $prospect->id, 'assigned_to' => $employee->id, 'created_by' => $employee->id,
            'stage' => 'validated', 'temperature' => 'hot', 'notes' => 'Fixture.',
        ]);
        $proposal = Proposal::create([
            'lead_id' => $lead->id, 'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id, 'created_by' => $employee->id, 'stage' => 'being_prepared',
        ]);
        $version = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'lifecycle_status' => ProposalVersionLifecycle::Draft,
            'customer_name_snapshot' => 'Acme Corp',
        ]);
        ProposalVersionLine::create([
            'proposal_version_id' => $version->id, 'line_number' => 1,
            'item_name' => 'Widget', 'quantity' => 1, 'unit_price' => 100,
        ]);
        $proposal->forceFill(['current_version_id' => $version->id])->save();

        return $proposal->fresh();
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    // --- Scenario 1: happy path all the way to Won + billing handoff ------

    public function test_happy_path_draft_to_won_produces_the_full_correct_evidence_chain(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->draftProposal($employee);
        $v1 = $proposal->currentVersion;

        app(ProposalVersionWorkflowService::class)->submit($v1->fresh(), $manager);
        app(ProposalVersionWorkflowService::class)->approve($v1->fresh(), $seniorManager);

        // Approval auto-generates the final PDF after commit (locked
        // Decision 3) — no explicit generate call needed here.
        $this->assertSame(1, ProposalPdfArtifact::query()->count());

        app(ProposalReleaseService::class)->release($v1->fresh(), $manager);

        app(ProposalSendService::class)->recordManualSend(
            $v1->fresh(['proposal']), $employee, ['client@example.com'], [], 'Your Proposal', null, now(), [], $this->key(),
        );

        $proposal->refresh();
        $this->assertSame(ProposalStage::Sent, $proposal->stage);
        $this->assertSame(ProposalVersionLifecycle::Sent, $v1->fresh()->lifecycle_status);

        app(ProposalClientResponseService::class)->recordAccepted($v1->fresh(), $manager, 'Client signed off.', $this->key());

        $proposal->refresh();
        $this->assertSame(ProposalOutcome::Won, $proposal->outcome);
        $this->assertSame(ProposalStage::CustomerAccepted, $proposal->stage);
        $this->assertSame($v1->getKey(), $proposal->winning_version_id);
        $this->assertEquals($v1->fresh()->grand_total, $proposal->value);

        $handoff = ProposalBillingHandoff::query()->where('proposal_id', $proposal->getKey())->sole();
        $this->assertSame(ProposalBillingHandoffStatus::Pending, $handoff->status);
        $this->assertSame($v1->getKey(), $handoff->winning_version_id);
    }

    // --- Scenario 2: Return for Revision (Manager-side, pre-send) ---------

    public function test_return_for_revision_creates_a_new_draft_and_leaves_the_original_frozen(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->draftProposal($employee);
        $v1 = $proposal->currentVersion;

        app(ProposalVersionWorkflowService::class)->submit($v1->fresh(), $manager);
        // Return for Revision is a Senior Manager action (final-approval
        // authority), unlike Approve which forbids self-approval by the
        // same actor who submitted — see ProposalVersionPolicy::returnForRevision().
        $v2 = app(ProposalVersionWorkflowService::class)->returnForRevision($v1->fresh(), $seniorManager, 'Please fix the GSTIN.');

        $this->assertSame(ProposalVersionLifecycle::ReturnedForRevision, $v1->fresh()->lifecycle_status);
        $this->assertSame('Please fix the GSTIN.', $v1->fresh()->return_reason);
        $this->assertSame($v2->getKey(), $v1->fresh()->superseded_by_version_id);
        $this->assertSame(ProposalVersionLifecycle::Draft, $v2->fresh()->lifecycle_status);
        $this->assertSame($v2->getKey(), $proposal->fresh()->current_version_id);
        // No stage/outcome side effect at all — this is purely a commercial
        // Version-level detour, independent of the Proposal's own stage.
        $this->assertSame(ProposalStage::BeingPrepared, $proposal->fresh()->stage);
        $this->assertNull($proposal->fresh()->outcome);
    }

    // --- Scenario 3: Customer Revision Requested (post-send) --------------

    public function test_customer_revision_requested_reopens_a_draft_and_moves_stage_back(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->draftProposal($employee);
        $v1 = $proposal->currentVersion;

        app(ProposalVersionWorkflowService::class)->submit($v1->fresh(), $manager);
        app(ProposalVersionWorkflowService::class)->approve($v1->fresh(), $seniorManager);
        app(ProposalReleaseService::class)->release($v1->fresh(), $manager);
        app(ProposalSendService::class)->recordManualSend($v1->fresh(['proposal']), $employee, ['client@example.com'], [], null, null, now(), [], $this->key());

        $response = app(ProposalClientResponseService::class)->recordRevisionRequested(
            $v1->fresh(), $manager, 'Pricing needs to change.', 'Client wants a 10% discount.', $this->key(),
        );

        $proposal->refresh();
        $this->assertSame(ProposalStage::BeingPrepared, $proposal->stage);
        $this->assertNull($proposal->outcome);

        $newDraft = ProposalVersion::query()->findOrFail($response->resulting_draft_version_id);
        $this->assertSame(ProposalVersionLifecycle::Draft, $newDraft->lifecycle_status);
        $this->assertSame($newDraft->getKey(), $proposal->current_version_id);
        // The Sent V1 itself is untouched — still Sent, still readable history.
        $this->assertSame(ProposalVersionLifecycle::Sent, $v1->fresh()->lifecycle_status);
    }

    // --- Scenario 4: More Time / Decision Pending --------------------------

    public function test_more_time_puts_the_proposal_on_hold_and_creates_a_follow_up(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->draftProposal($employee);
        $v1 = $proposal->currentVersion;

        app(ProposalVersionWorkflowService::class)->submit($v1->fresh(), $manager);
        app(ProposalVersionWorkflowService::class)->approve($v1->fresh(), $seniorManager);
        app(ProposalReleaseService::class)->release($v1->fresh(), $manager);
        app(ProposalSendService::class)->recordManualSend($v1->fresh(['proposal']), $employee, ['client@example.com'], [], null, null, now(), [], $this->key());

        $followUpAt = now()->addDays(5);
        $response = app(ProposalClientResponseService::class)->recordMoreTime(
            $v1->fresh(), $manager, $followUpAt, 'Budget approval pending internally.', null, null, null, $this->key(),
        );

        $proposal->refresh();
        $this->assertSame(ProposalOutcome::Hold, $proposal->outcome);
        // Stage is deliberately untouched by More Time (locked Decision 18).
        $this->assertSame(ProposalStage::Sent, $proposal->stage);

        $followUp = FollowUp::query()->findOrFail($response->follow_up_id);
        $this->assertSame($followUpAt->format('Y-m-d H:i'), $followUp->follow_up_at->format('Y-m-d H:i'));
    }

    // --- Scenario 5: Rejected ------------------------------------------------

    public function test_rejected_marks_the_proposal_lost_with_the_customers_reason(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->draftProposal($employee);
        $v1 = $proposal->currentVersion;

        app(ProposalVersionWorkflowService::class)->submit($v1->fresh(), $manager);
        app(ProposalVersionWorkflowService::class)->approve($v1->fresh(), $seniorManager);
        app(ProposalReleaseService::class)->release($v1->fresh(), $manager);
        app(ProposalSendService::class)->recordManualSend($v1->fresh(['proposal']), $employee, ['client@example.com'], [], null, null, now(), [], $this->key());

        app(ProposalClientResponseService::class)->recordRejected($v1->fresh(), $manager, 'Went with a competitor.', null, $this->key());

        $proposal->refresh();
        $this->assertSame(ProposalOutcome::Lost, $proposal->outcome);
        $this->assertSame(ProposalStage::CustomerRejected, $proposal->stage);
        $this->assertStringContainsString('Went with a competitor.', (string) $proposal->notes);
        $this->assertSame(0, ProposalBillingHandoff::query()->where('proposal_id', $proposal->getKey())->count());
    }

    // --- Scenario 6: PDF correction and Re-Release --------------------------

    public function test_pdf_correction_after_release_makes_it_stale_and_re_release_clears_that(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->draftProposal($employee);
        $v1 = $proposal->currentVersion;

        app(ProposalVersionWorkflowService::class)->submit($v1->fresh(), $manager);
        app(ProposalVersionWorkflowService::class)->approve($v1->fresh(), $seniorManager);
        $originalArtifact = ProposalPdfArtifact::query()->sole();

        app(ProposalReleaseService::class)->release($v1->fresh(), $manager);
        $this->assertFalse($v1->fresh()->isReleaseStale());

        $correctedArtifact = app(ProposalPdfArtifactService::class)->correct($v1->fresh(), $manager, 'Fixed a typo in the scope section.');

        $this->assertNotNull($originalArtifact->fresh()->superseded_at);
        $this->assertTrue($v1->fresh()->isReleaseStale());

        app(ProposalReleaseService::class)->release($v1->fresh(), $manager, 'Re-released after the PDF fix.');

        $refreshed = $v1->fresh();
        $this->assertFalse($refreshed->isReleaseStale());
        $this->assertSame($correctedArtifact->getKey(), $refreshed->released_pdf_artifact_id);
    }

    // --- Scenario 7: Resend --------------------------------------------------

    public function test_resend_creates_a_second_send_row_without_disturbing_the_first(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->draftProposal($employee);
        $v1 = $proposal->currentVersion;

        app(ProposalVersionWorkflowService::class)->submit($v1->fresh(), $manager);
        app(ProposalVersionWorkflowService::class)->approve($v1->fresh(), $seniorManager);
        app(ProposalReleaseService::class)->release($v1->fresh(), $manager);

        app(ProposalSendService::class)->recordManualSend($v1->fresh(['proposal']), $employee, ['client@example.com'], [], null, null, now(), [], $this->key());
        $firstSentAt = $v1->fresh()->sent_at;

        app(ProposalSendService::class)->recordManualSend($v1->fresh(['proposal']), $employee, ['client-cc@example.com'], [], 'Following up', null, now(), [], $this->key());

        $this->assertSame(2, ProposalSend::query()->where('proposal_version_id', $v1->getKey())->count());
        // The FIRST successful send's timestamp is the one that matters for
        // the lifecycle transition — a resend never overwrites it.
        $this->assertTrue($firstSentAt->equalTo($v1->fresh()->sent_at));
        $this->assertSame(ProposalVersionLifecycle::Sent, $v1->fresh()->lifecycle_status);
        $this->assertSame(ProposalStage::Sent, $proposal->fresh()->stage);
    }

    // --- Scenario 8: idempotent replay ---------------------------------------

    public function test_replaying_the_same_send_idempotency_key_with_the_same_payload_never_duplicates(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->draftProposal($employee);
        $v1 = $proposal->currentVersion;

        app(ProposalVersionWorkflowService::class)->submit($v1->fresh(), $manager);
        app(ProposalVersionWorkflowService::class)->approve($v1->fresh(), $seniorManager);
        app(ProposalReleaseService::class)->release($v1->fresh(), $manager);

        $key = $this->key();
        $sentAt = now();

        $first = app(ProposalSendService::class)->recordManualSend($v1->fresh(['proposal']), $employee, ['client@example.com'], [], 'Subject', 'Notes', $sentAt, [], $key);
        $second = app(ProposalSendService::class)->recordManualSend($v1->fresh(['proposal']), $employee, ['client@example.com'], [], 'Subject', 'Notes', $sentAt, [], $key);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, ProposalSend::query()->where('proposal_version_id', $v1->getKey())->count());

        // Same guarantee holds for a Client Response replay against the
        // same idempotency key once the Proposal reaches a terminal state.
        app(ProposalSendService::class)->recordManualSend($v1->fresh(['proposal']), $employee, ['client@example.com'], [], null, null, now(), [], $this->key());
        $responseKey = $this->key();
        $firstResponse = app(ProposalClientResponseService::class)->recordAccepted($v1->fresh(), $manager, 'Signed.', $responseKey);
        $secondResponse = app(ProposalClientResponseService::class)->recordAccepted($v1->fresh(), $manager, 'Signed.', $responseKey);

        $this->assertSame($firstResponse->getKey(), $secondResponse->getKey());
        $this->assertSame(1, ProposalBillingHandoff::query()->where('proposal_id', $proposal->getKey())->count());
    }
}
