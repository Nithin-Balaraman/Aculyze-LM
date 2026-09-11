<?php

namespace Tests\Feature;

use App\Enums\ProposalOutcome;
use App\Enums\ProposalSendMethod;
use App\Enums\ProposalSendStatus;
use App\Enums\ProposalStage;
use App\Enums\ProposalVersionLifecycle;
use App\Enums\UserRole;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalPdfArtifact;
use App\Models\ProposalSend;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use App\Models\Prospect;
use App\Models\User;
use App\Services\ProposalPdfArtifactService;
use App\Services\ProposalReleaseService;
use App\Services\ProposalSendService;
use App\Services\ProposalVersionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

/**
 * Phase 4A-3.3, Sections G-K: ProposalSendService — a controlled RECORDING
 * of a manual send that already happened outside Aculyze-LM. Covers manual-
 * send validation (Section G), first-send lifecycle/stage transition
 * (Section J, locked Decision 18), resend (locked Decision 23), per-
 * operation idempotency (Section O), and terminal-outcome records-only
 * resend (Section K, locked Decision 25).
 */
class ProposalSendTest extends TestCase
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

    private function approvedVersion(User $employee, User $submitter, User $approver): ProposalVersion
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

        app(ProposalVersionWorkflowService::class)->submit($version->fresh(), $submitter);
        app(ProposalVersionWorkflowService::class)->approve($version->fresh(), $approver);

        return $version->fresh(['proposal']);
    }

    /** Approved, current-primary PDF exists, and Released. */
    private function releasedVersion(User $employee, User $submitter, User $approver, User $releaser): ProposalVersion
    {
        $version = $this->approvedVersion($employee, $submitter, $approver);
        app(ProposalReleaseService::class)->release($version->fresh(), $releaser);

        return $version->fresh(['proposal']);
    }

    private function sendKey(): string
    {
        return (string) \Illuminate\Support\Str::uuid();
    }

    // --- Manual send validation (14-21) ---

    public function test_send_is_blocked_when_no_release_exists(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->approvedVersion($employee, $manager, $seniorManager);

        $this->expectException(LogicException::class);

        app(ProposalSendService::class)->recordManualSend(
            $version, $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey()
        );

        $this->assertSame(0, ProposalSend::query()->count());
    }

    public function test_send_is_blocked_when_release_is_stale(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->releasedVersion($employee, $manager, $seniorManager, $manager);
        app(ProposalPdfArtifactService::class)->correct($version->fresh(), $manager, 'Correction.');

        try {
            app(ProposalSendService::class)->recordManualSend(
                $version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey()
            );
            $this->fail('Expected a LogicException for a stale Release.');
        } catch (LogicException $e) {
            $this->assertSame(0, ProposalSend::query()->count());
        }
    }

    public function test_blank_to_recipients_is_blocked_and_writes_no_row(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->releasedVersion($employee, $manager, $seniorManager, $manager);

        try {
            app(ProposalSendService::class)->recordManualSend(
                $version->fresh(), $employee, [], [], null, null, now(), [], $this->sendKey()
            );
            $this->fail('Expected a LogicException for blank To recipients.');
        } catch (LogicException $e) {
            $this->assertSame(0, ProposalSend::query()->count());
        }
    }

    public function test_future_sent_at_is_blocked_and_writes_no_row(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->releasedVersion($employee, $manager, $seniorManager, $manager);

        try {
            app(ProposalSendService::class)->recordManualSend(
                $version->fresh(), $employee, ['a@b.com'], [], null, null, now()->addDay(), [], $this->sendKey()
            );
            $this->fail('Expected a LogicException for a future sent_at.');
        } catch (LogicException $e) {
            $this->assertSame(0, ProposalSend::query()->count());
        }
    }

    public function test_sent_at_before_released_at_is_blocked_and_writes_no_row(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->releasedVersion($employee, $manager, $seniorManager, $manager);

        try {
            app(ProposalSendService::class)->recordManualSend(
                $version->fresh(), $employee, ['a@b.com'], [], null, null, $version->fresh()->released_at->subDay(), [], $this->sendKey()
            );
            $this->fail('Expected a LogicException for sent_at before released_at.');
        } catch (LogicException $e) {
            $this->assertSame(0, ProposalSend::query()->count());
        }
    }

    public function test_send_is_blocked_when_released_artifact_no_longer_matches_current_primary(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->releasedVersion($employee, $manager, $seniorManager, $manager);

        // Force a mismatch directly (simulating stale data reaching the
        // service despite isReleaseStale() normally catching this first).
        $version->fresh()->forceFill(['released_pdf_artifact_id' => null])->saveQuietly();

        try {
            app(ProposalSendService::class)->recordManualSend(
                $version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey()
            );
            $this->fail('Expected a LogicException for a released-artifact mismatch.');
        } catch (LogicException $e) {
            $this->assertSame(0, ProposalSend::query()->count());
        }
    }

    public function test_employee_assignment_authorization_is_enforced(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->releasedVersion($employee, $manager, $seniorManager, $manager);

        $otherEmployee = User::factory()->create(['role' => UserRole::Employee, 'manager_id' => $manager->id]);

        $this->expectException(LogicException::class);

        app(ProposalSendService::class)->recordManualSend(
            $version->fresh(), $otherEmployee, ['a@b.com'], [], null, null, now(), [], $this->sendKey()
        );
    }

    public function test_validation_failure_never_creates_a_failed_send_status(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->approvedVersion($employee, $manager, $seniorManager);

        try {
            app(ProposalSendService::class)->recordManualSend(
                $version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey()
            );
        } catch (LogicException) {
        }

        $this->assertSame(0, ProposalSend::query()->where('status', ProposalSendStatus::Failed)->count());
        $this->assertSame(0, ProposalSend::query()->count());
    }

    // --- First send (22-31) ---

    public function test_first_send_creates_a_proposal_sends_row(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->releasedVersion($employee, $manager, $seniorManager, $manager);

        $send = app(ProposalSendService::class)->recordManualSend(
            $version->fresh(), $employee, ['a@b.com'], [], 'Subject', 'Notes', now(), [], $this->sendKey()
        );

        $this->assertNotNull($send->getKey());
        $this->assertSame(1, ProposalSend::query()->count());
    }

    public function test_first_send_method_manual_status_sent(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->releasedVersion($employee, $manager, $seniorManager, $manager);

        $send = app(ProposalSendService::class)->recordManualSend(
            $version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey()
        );

        $this->assertSame(ProposalSendMethod::Manual, $send->method);
        $this->assertSame(ProposalSendStatus::Sent, $send->status);
    }

    public function test_first_send_body_is_null(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->releasedVersion($employee, $manager, $seniorManager, $manager);

        $send = app(ProposalSendService::class)->recordManualSend(
            $version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey()
        );

        $this->assertNull($send->body);
    }

    public function test_first_send_snapshots_recipients_subject_notes(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->releasedVersion($employee, $manager, $seniorManager, $manager);

        $send = app(ProposalSendService::class)->recordManualSend(
            $version->fresh(), $employee, ['a@b.com', 'c@d.com'], ['cc@d.com'], 'The Subject', 'Some notes', now(), [], $this->sendKey()
        );

        $this->assertSame(['a@b.com', 'c@d.com'], $send->to_recipients);
        $this->assertSame(['cc@d.com'], $send->cc_recipients);
        $this->assertSame('The Subject', $send->subject);
        $this->assertSame('Some notes', $send->notes);
    }

    public function test_first_send_records_exact_pdf_artifact_id(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->releasedVersion($employee, $manager, $seniorManager, $manager);
        $currentPrimary = app(ProposalPdfArtifactService::class)->currentPrimary($version->fresh());

        $send = app(ProposalSendService::class)->recordManualSend(
            $version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey()
        );

        $this->assertSame($currentPrimary->getKey(), $send->pdf_artifact_id);
    }

    public function test_first_send_transitions_version_approved_to_sent(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->releasedVersion($employee, $manager, $seniorManager, $manager);

        app(ProposalSendService::class)->recordManualSend(
            $version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey()
        );

        $this->assertSame(ProposalVersionLifecycle::Sent, $version->fresh()->lifecycle_status);
    }

    public function test_first_send_sets_version_sent_at_once(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->releasedVersion($employee, $manager, $seniorManager, $manager);
        $sentAt = now();

        app(ProposalSendService::class)->recordManualSend(
            $version->fresh(), $employee, ['a@b.com'], [], null, null, $sentAt, [], $this->sendKey()
        );

        $this->assertNotNull($version->fresh()->sent_at);
        $this->assertEqualsWithDelta($sentAt->timestamp, $version->fresh()->sent_at->timestamp, 2);
    }

    public function test_first_send_moves_proposal_stage_to_sent(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->releasedVersion($employee, $manager, $seniorManager, $manager);

        app(ProposalSendService::class)->recordManualSend(
            $version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey()
        );

        $this->assertSame(ProposalStage::Sent, $version->fresh()->proposal->stage);
    }

    public function test_first_send_leaves_outcome_unchanged(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->releasedVersion($employee, $manager, $seniorManager, $manager);

        app(ProposalSendService::class)->recordManualSend(
            $version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey()
        );

        $this->assertNull($version->fresh()->proposal->outcome);
    }

    public function test_first_send_resets_stage_changed_at_because_stage_genuinely_changed(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->releasedVersion($employee, $manager, $seniorManager, $manager);
        $proposal = $version->fresh()->proposal;
        $originalStageChangedAt = $proposal->stage_changed_at;

        Carbon::setTestNow(now()->addMinutes(5));

        app(ProposalSendService::class)->recordManualSend(
            $version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey()
        );

        Carbon::setTestNow();

        $this->assertNotNull($version->fresh()->proposal->stage_changed_at);
        $this->assertTrue($version->fresh()->proposal->stage_changed_at->gt($originalStageChangedAt));
    }

    // --- Resend (32-38) ---

    public function test_resend_creates_a_second_send_row(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->releasedVersion($employee, $manager, $seniorManager, $manager);

        app(ProposalSendService::class)->recordManualSend($version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey());
        app(ProposalSendService::class)->recordManualSend($version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey());

        $this->assertSame(2, ProposalSend::query()->count());
    }

    public function test_resend_keeps_version_sent(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->releasedVersion($employee, $manager, $seniorManager, $manager);

        app(ProposalSendService::class)->recordManualSend($version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey());
        app(ProposalSendService::class)->recordManualSend($version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey());

        $this->assertSame(ProposalVersionLifecycle::Sent, $version->fresh()->lifecycle_status);
    }

    public function test_resend_does_not_overwrite_first_sent_at(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->releasedVersion($employee, $manager, $seniorManager, $manager);
        $firstSentAt = now();

        app(ProposalSendService::class)->recordManualSend($version->fresh(), $employee, ['a@b.com'], [], null, null, $firstSentAt, [], $this->sendKey());
        $versionSentAtAfterFirst = $version->fresh()->sent_at;

        app(ProposalSendService::class)->recordManualSend($version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey());

        $this->assertTrue($versionSentAtAfterFirst->equalTo($version->fresh()->sent_at));
    }

    public function test_resend_keeps_stage_sent(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->releasedVersion($employee, $manager, $seniorManager, $manager);

        app(ProposalSendService::class)->recordManualSend($version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey());
        app(ProposalSendService::class)->recordManualSend($version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey());

        $this->assertSame(ProposalStage::Sent, $version->fresh()->proposal->stage);
    }

    public function test_resend_does_not_reset_stage_changed_at(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->releasedVersion($employee, $manager, $seniorManager, $manager);

        app(ProposalSendService::class)->recordManualSend($version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey());
        $stageChangedAtAfterFirst = $version->fresh()->proposal->stage_changed_at;

        Carbon::setTestNow(now()->addMinutes(5));
        app(ProposalSendService::class)->recordManualSend($version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey());
        Carbon::setTestNow();

        $this->assertTrue($stageChangedAtAfterFirst->equalTo($version->fresh()->proposal->stage_changed_at));
    }

    public function test_resend_reuses_the_same_valid_release(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->releasedVersion($employee, $manager, $seniorManager, $manager);
        $releasedAt = $version->fresh()->released_at;

        app(ProposalSendService::class)->recordManualSend($version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey());
        app(ProposalSendService::class)->recordManualSend($version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey());

        $this->assertTrue($releasedAt->equalTo($version->fresh()->released_at));
    }

    public function test_fresh_idempotency_key_permits_a_genuine_resend(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->releasedVersion($employee, $manager, $seniorManager, $manager);

        $first = app(ProposalSendService::class)->recordManualSend($version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey());
        $second = app(ProposalSendService::class)->recordManualSend($version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey());

        $this->assertNotSame($first->getKey(), $second->getKey());
    }

    // --- Idempotency (39-40) ---

    public function test_same_key_and_same_operation_does_not_duplicate(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->releasedVersion($employee, $manager, $seniorManager, $manager);
        $key = $this->sendKey();
        $sentAt = now();

        $first = app(ProposalSendService::class)->recordManualSend($version->fresh(), $employee, ['a@b.com'], [], 'S', 'N', $sentAt, [], $key);
        $second = app(ProposalSendService::class)->recordManualSend($version->fresh(), $employee, ['a@b.com'], [], 'S', 'N', $sentAt, [], $key);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, ProposalSend::query()->count());
    }

    public function test_same_key_with_a_materially_different_payload_is_rejected(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->releasedVersion($employee, $manager, $seniorManager, $manager);
        $key = $this->sendKey();

        app(ProposalSendService::class)->recordManualSend($version->fresh(), $employee, ['a@b.com'], [], 'S', 'N', now(), [], $key);

        $this->expectException(LogicException::class);

        app(ProposalSendService::class)->recordManualSend($version->fresh(), $employee, ['different@b.com'], [], 'S', 'N', now(), [], $key);
    }

    // --- Terminal-outcome records-only resend (41-43) ---

    public function test_terminal_won_proposal_with_sent_version_and_valid_release_can_resend(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->releasedVersion($employee, $manager, $seniorManager, $manager);
        app(ProposalSendService::class)->recordManualSend($version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey());

        $proposal = $version->fresh()->proposal;
        $proposal->forceFill(['outcome' => ProposalOutcome::Won, 'notes' => 'Won the deal.'])->save();

        $send = app(ProposalSendService::class)->recordManualSend($version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey());

        $this->assertNotNull($send->getKey());
        $this->assertSame(2, ProposalSend::query()->count());
    }

    public function test_terminal_resend_leaves_outcome_and_stage_unchanged(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->releasedVersion($employee, $manager, $seniorManager, $manager);
        app(ProposalSendService::class)->recordManualSend($version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey());

        $proposal = $version->fresh()->proposal;
        $proposal->forceFill(['outcome' => ProposalOutcome::Lost, 'notes' => 'Lost the deal.'])->save();

        app(ProposalSendService::class)->recordManualSend($version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey());

        $this->assertSame(ProposalOutcome::Lost, $version->fresh()->proposal->outcome);
        $this->assertSame(ProposalStage::Sent, $version->fresh()->proposal->stage);
    }

    public function test_terminal_resend_creates_no_client_response_or_billing_handoff(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $version = $this->releasedVersion($employee, $manager, $seniorManager, $manager);
        app(ProposalSendService::class)->recordManualSend($version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey());

        $proposal = $version->fresh()->proposal;
        $proposal->forceFill(['outcome' => ProposalOutcome::Won, 'notes' => 'Won.'])->save();

        app(ProposalSendService::class)->recordManualSend($version->fresh(), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey());

        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('proposal_client_responses')->count());
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('proposal_billing_handoffs')->count());
    }
}
