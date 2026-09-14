<?php

namespace Tests\Feature;

use App\Enums\CallNextAction;
use App\Enums\CallOutcome;
use App\Enums\ProfileSentMode;
use App\Enums\ProfileSentStatus;
use App\Filament\Pages\PipelineBoard;
use App\Models\CallRecord;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Modal-routing audit: PipelineBoard::callLogFormSchema() previously
 * hand-copied a subset of CallRecordResource's own "Call Details" fields
 * and omitted `next_action` (required by CallRecord's own model guard
 * whenever outcome is Other) and the whole "Profile Sent" section (required
 * whenever outcome is Profile Requested) — logging a new Call via the
 * board's cross-drop with either outcome could therefore never actually be
 * submitted, always failing with a LogicException from CallRecord::
 * booted()'s own guard, with no field in the dialog to supply what it
 * demanded. Fixed by reusing CallRecordResource's own schema methods
 * verbatim instead of a hand-copied list that could silently drift.
 */
class PipelineBoardCallOtherAndProfileRequestedCrossDropTest extends TestCase
{
    use RefreshDatabase;

    private function invokePerformCrossDrop(PipelineBoard $board, array $arguments, array $data = []): void
    {
        $method = new \ReflectionMethod($board, 'performCrossDrop');
        $method->setAccessible(true);
        $method->invoke($board, $arguments, $data);
    }

    public function test_others_outcome_with_an_explicit_next_action_can_be_logged_via_cross_drop(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $call = CallRecord::create([
                'prospect_id' => $prospect->id, 'user_id' => $user->id,
                'called_at' => now()->subDay(), 'outcome' => CallOutcome::NoAnswer,
            ]);

            $this->invokePerformCrossDrop(
                app(PipelineBoard::class),
                ['sourceResource' => 'call', 'sourceId' => $call->id, 'destResource' => 'lead', 'destStage' => 'requirement_collection'],
                [
                    'outcome' => CallOutcome::Others->value,
                    'next_action' => CallNextAction::CreateLead->value,
                    'notes' => 'Unusual request outside the normal outcomes.',
                    'called_at' => now(),
                ],
            );

            $this->assertSame(2, CallRecord::query()->count());
            $newCall = CallRecord::query()->where('id', '!=', $call->id)->sole();
            $this->assertSame(CallOutcome::Others, $newCall->outcome);
            $this->assertSame(CallNextAction::CreateLead, $newCall->next_action);

            // The explicit CreateLead next_action actually created the Lead.
            $this->assertSame(1, Lead::query()->where('prospect_id', $prospect->id)->count());
        });
    }

    public function test_others_outcome_still_requires_notes_and_next_action_server_side(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $call = CallRecord::create([
                'prospect_id' => $prospect->id, 'user_id' => $user->id,
                'called_at' => now()->subDay(), 'outcome' => CallOutcome::NoAnswer,
            ]);

            $threw = false;

            try {
                $this->invokePerformCrossDrop(
                    app(PipelineBoard::class),
                    ['sourceResource' => 'call', 'sourceId' => $call->id, 'destResource' => 'lead', 'destStage' => 'requirement_collection'],
                    ['outcome' => CallOutcome::Others->value, 'notes' => 'Missing next action.', 'called_at' => now()],
                );
            } catch (\Filament\Support\Exceptions\Halt $e) {
                $threw = true;
            }

            $this->assertTrue($threw);
            $this->assertSame(1, CallRecord::query()->count());
        });
    }

    public function test_profile_requested_outcome_with_a_sent_status_can_be_logged_via_cross_drop(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $call = CallRecord::create([
                'prospect_id' => $prospect->id, 'user_id' => $user->id,
                'called_at' => now()->subDay(), 'outcome' => CallOutcome::NoAnswer,
            ]);

            $this->invokePerformCrossDrop(
                app(PipelineBoard::class),
                ['sourceResource' => 'call', 'sourceId' => $call->id, 'destResource' => 'follow_up', 'destStage' => 'pending'],
                [
                    'outcome' => CallOutcome::ProfileRequested->value,
                    'notes' => 'Sent the company profile over email.',
                    'called_at' => now(),
                    'profile_sent_status' => ProfileSentStatus::Sent->value,
                    'profile_sent_at' => now(),
                    'profile_sent_mode' => ProfileSentMode::Email->value,
                ],
            );

            $this->assertSame(2, CallRecord::query()->count());
            $newCall = CallRecord::query()->where('id', '!=', $call->id)->sole();
            $this->assertSame(CallOutcome::ProfileRequested, $newCall->outcome);
            $this->assertSame(ProfileSentStatus::Sent, $newCall->profile_sent_status);
        });
    }

    public function test_profile_requested_outcome_without_a_sent_status_is_rejected_server_side(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $call = CallRecord::create([
                'prospect_id' => $prospect->id, 'user_id' => $user->id,
                'called_at' => now()->subDay(), 'outcome' => CallOutcome::NoAnswer,
            ]);

            $threw = false;

            try {
                $this->invokePerformCrossDrop(
                    app(PipelineBoard::class),
                    ['sourceResource' => 'call', 'sourceId' => $call->id, 'destResource' => 'follow_up', 'destStage' => 'pending'],
                    ['outcome' => CallOutcome::ProfileRequested->value, 'notes' => 'No status supplied.', 'called_at' => now()],
                );
            } catch (\Filament\Support\Exceptions\Halt $e) {
                $threw = true;
            }

            $this->assertTrue($threw);
            $this->assertSame(1, CallRecord::query()->count());
        });
    }
}
