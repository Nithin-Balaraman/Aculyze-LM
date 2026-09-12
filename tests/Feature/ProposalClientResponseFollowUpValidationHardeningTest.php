<?php

namespace Tests\Feature;

use App\Enums\ProposalStage;
use App\Enums\ProposalVersionLifecycle;
use App\Enums\UserRole;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalClientResponse;
use App\Models\ProposalVersion;
use App\Models\Prospect;
use App\Models\User;
use App\Services\ProposalClientResponseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 4A-3.4 hardening pass: `follow_up_at` on the More Time and Other ->
 * Create Follow-Up response paths must be validated as controlled business
 * validation (a catchable LogicException), never a raw uncatchable
 * PHP TypeError, regardless of caller.
 */
class ProposalClientResponseFollowUpValidationHardeningTest extends TestCase
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
    private function sentProposal(User $employee): array
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
        $version = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'lifecycle_status' => ProposalVersionLifecycle::Sent,
            'customer_name_snapshot' => 'Acme Corp',
            'grand_total' => 1000,
        ]);
        $proposal->forceFill(['current_version_id' => $version->id])->save();

        return [$proposal->fresh(), $version->fresh(['proposal'])];
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    // --- A/B/C: Other -> Create Follow-Up ---

    public static function invalidFollowUpAtProvider(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'whitespace only' => ['   '],
            'malformed date' => ['not-a-date'],
        ];
    }

    #[DataProvider('invalidFollowUpAtProvider')]
    public function test_other_create_follow_up_rejects_invalid_follow_up_at(mixed $invalid): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);
        $originalStage = $proposal->fresh()->stage;

        try {
            app(ProposalClientResponseService::class)->recordOtherCreateFollowUp(
                $version, $employee, $invalid, 'Will call back next week', null, null, null, $this->key()
            );
            $this->fail('Expected a LogicException to be thrown.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(LogicException::class, $e);
            $this->assertNotInstanceOf(\TypeError::class, $e);
            $this->assertSame('A valid follow-up date/time is required.', $e->getMessage());
        }

        $this->assertSame(0, ProposalClientResponse::query()->count());
        $this->assertSame(0, FollowUp::query()->count());
        $this->assertSame($originalStage, $proposal->fresh()->stage);
        $this->assertNull($proposal->fresh()->last_client_activity_at);
    }

    public function test_other_create_follow_up_succeeds_with_valid_date(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);
        $followUpAt = now()->addDays(3)->startOfSecond();

        $response = app(ProposalClientResponseService::class)->recordOtherCreateFollowUp(
            $version, $employee, $followUpAt->toDateTimeString(), 'Call back next week', null, null, null, $this->key()
        );

        $this->assertNotNull($response->getKey());
        $this->assertSame(1, ProposalClientResponse::query()->count());
        $followUp = FollowUp::query()->sole();
        $this->assertTrue($followUpAt->equalTo($followUp->follow_up_at));
        $this->assertSame(ProposalStage::Sent, $proposal->fresh()->stage);
        $this->assertNotNull($proposal->fresh()->last_client_activity_at);
    }

    public function test_other_create_follow_up_accepts_a_real_carbon_instance_directly(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [, $version] = $this->sentProposal($employee);
        $followUpAt = now()->addDays(2);

        $response = app(ProposalClientResponseService::class)->recordOtherCreateFollowUp(
            $version, $employee, $followUpAt, 'Call back', null, null, null, $this->key()
        );

        $this->assertNotNull($response->getKey());
    }

    public function test_other_create_follow_up_replay_still_reuses_response_without_duplicate_follow_up(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [, $version] = $this->sentProposal($employee);
        $key = $this->key();
        $followUpAt = now()->addDays(3)->toDateTimeString();

        $first = app(ProposalClientResponseService::class)->recordOtherCreateFollowUp(
            $version, $employee, $followUpAt, 'Call back next week', null, null, null, $key
        );
        $second = app(ProposalClientResponseService::class)->recordOtherCreateFollowUp(
            $version->fresh(), $employee, $followUpAt, 'Call back next week', null, null, null, $key
        );

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, FollowUp::query()->count());
        $this->assertSame(1, ProposalClientResponse::query()->count());
    }

    // --- E: More Time consistency ---

    #[DataProvider('invalidFollowUpAtProvider')]
    public function test_more_time_rejects_invalid_follow_up_at(mixed $invalid): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);
        $originalStage = $proposal->fresh()->stage;

        try {
            app(ProposalClientResponseService::class)->recordMoreTime(
                $version, $employee, $invalid, 'Budget review pending', null, null, null, $this->key()
            );
            $this->fail('Expected a LogicException to be thrown.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(LogicException::class, $e);
            $this->assertNotInstanceOf(\TypeError::class, $e);
            $this->assertSame('A valid follow-up date/time is required.', $e->getMessage());
        }

        $this->assertSame(0, ProposalClientResponse::query()->count());
        $this->assertSame(0, FollowUp::query()->count());
        $this->assertSame($originalStage, $proposal->fresh()->stage);
        $this->assertNull($proposal->fresh()->last_client_activity_at);
    }

    public function test_more_time_still_succeeds_with_valid_date(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        [, $version] = $this->sentProposal($employee);
        $followUpAt = now()->addDays(5)->startOfSecond();

        $response = app(ProposalClientResponseService::class)->recordMoreTime(
            $version, $employee, $followUpAt->toDateTimeString(), 'Budget review pending', null, null, null, $this->key()
        );

        $this->assertNotNull($response->getKey());
        $followUp = FollowUp::query()->sole();
        $this->assertTrue($followUpAt->equalTo($followUp->follow_up_at));
    }
}
