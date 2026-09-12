<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Enums\ProposalOutcome;
use App\Enums\ProposalStage;
use App\Enums\UserRole;
use App\Filament\Pages\PipelineBoard;
use App\Filament\Resources\ProposalResource\Pages\CreateProposal;
use App\Filament\Resources\ProposalResource\Pages\EditProposal;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Phase 4A-3.5 cutover tripwire suite: the new Phase 4A-3 workflow
 * (ProposalSendService, ProposalClientResponseService) is now the ONLY
 * supported writer for Proposal commercial lifecycle/outcome state, the
 * legacy Pipeline Board / generic-form mutation routes are refused, and the
 * DB itself enforces "Won requires a winning Version". These tests exist so
 * a future change cannot quietly reopen any of these bypasses without a
 * test noticing.
 */
class ProposalOutcomeCutoverGateTest extends TestCase
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

    private function proposal(User $employee, ProposalStage $stage = ProposalStage::BeingPrepared): Proposal
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $lead = Lead::create([
            'prospect_id' => $prospect->id, 'assigned_to' => $employee->id, 'created_by' => $employee->id,
            'stage' => LeadStage::Validated, 'temperature' => 'hot', 'notes' => 'Fixture.',
        ]);

        return Proposal::create([
            'lead_id' => $lead->id, 'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id, 'created_by' => $employee->id, 'stage' => $stage,
        ]);
    }

    // -----------------------------------------------------------------
    // 1. Gate is CLOSED
    // -----------------------------------------------------------------

    public function test_gate_document_says_closed(): void
    {
        $gate = file_get_contents(base_path('docs/PHASE4_OUTCOME_CUTOVER_GATE.md'));

        $this->assertStringContainsString('Status: CLOSED', $gate);
        $this->assertStringNotContainsString('Status: OPEN', $gate);
    }

    // -----------------------------------------------------------------
    // 2. PipelineBoard
    // -----------------------------------------------------------------

    public function test_proposal_source_drag_is_refused(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $proposal = $this->proposal($employee, ProposalStage::Sent);
        $originalStage = $proposal->stage;
        $originalOutcome = $proposal->outcome;

        $this->actingAs($employee);

        Livewire::test(PipelineBoard::class)
            ->mountAction('drop', ['resource' => 'proposal', 'id' => $proposal->id, 'stage' => ProposalStage::CustomerAccepted->value])
            ->assertSee('Proposal workflow is managed from the Proposal record.');

        $fresh = $proposal->fresh();
        $this->assertSame($originalStage, $fresh->stage);
        $this->assertSame($originalOutcome, $fresh->outcome);
    }

    public function test_proposal_drag_to_downstream_lane_as_cross_drop_source_is_refused(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $proposal = $this->proposal($employee, ProposalStage::Sent);

        $board = app(PipelineBoard::class);
        $method = new ReflectionMethod($board, 'crossDropSupported');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($board, 'proposal', 'follow_up', $proposal, ''));
        $this->assertFalse($method->invoke($board, 'proposal', 'lead', $proposal, ''));
        $this->assertFalse($method->invoke($board, 'proposal', 'appointment', $proposal, ''));
    }

    public function test_lead_cross_drop_into_a_terminal_proposal_stage_is_refused_and_creates_nothing(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $lead = Lead::create([
            'prospect_id' => $prospect->id, 'assigned_to' => $employee->id, 'created_by' => $employee->id,
            'stage' => LeadStage::Validated, 'status' => LeadStatus::ProposalRequired, 'temperature' => 'hot', 'notes' => 'Fixture.',
        ]);

        $this->actingAs($employee);

        $board = app(PipelineBoard::class);
        $method = new ReflectionMethod($board, 'performCrossDrop');
        $method->setAccessible(true);
        $method->invoke($board, ['sourceResource' => 'lead', 'sourceId' => $lead->id, 'destResource' => 'proposal', 'destStage' => 'customer_accepted'], []);

        $this->assertSame(0, Proposal::where('lead_id', $lead->id)->count());
    }

    public function test_clear_cutover_message_is_surfaced_for_a_refused_proposal_drag(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $proposal = $this->proposal($employee);

        $board = app(PipelineBoard::class);
        $method = new ReflectionMethod($board, 'unsupportedDropReason');
        $method->setAccessible(true);

        $this->assertSame(
            'Proposal workflow is managed from the Proposal record.',
            $method->invoke($board, ['resource' => 'proposal', 'stage' => ProposalStage::Sent->value])
        );
    }

    // -----------------------------------------------------------------
    // 3. Legacy WorkflowTransitionService continuation
    // -----------------------------------------------------------------

    /**
     * transitionProposalContinuation() was found, on inventory, to already
     * never mutate Proposal.stage/outcome directly (it only reads outcome
     * to gate, then creates a downstream FollowUp/Demo/Lead-clarification
     * record) — so it satisfies the cutover requirement as-is and remains
     * a legitimate feature (ProposalResource's "Continue" action). This is
     * a codebase-shape tripwire proving that remains true.
     */
    public function test_legacy_proposal_continuation_method_never_writes_proposal_stage_or_outcome(): void
    {
        $contents = file_get_contents(app_path('Services/WorkflowTransitionService.php'));

        $start = strpos($contents, 'function transitionProposalContinuation');
        $this->assertNotFalse($start, 'transitionProposalContinuation() must exist.');

        // Isolate roughly the method body (up to the next method boundary)
        // rather than the whole file, so this only inspects what the method
        // itself does.
        $end = strpos($contents, "\n    private function requireLeadForProposal", $start);
        $body = substr($contents, $start, ($end !== false ? $end : $start + 2000) - $start);

        $this->assertStringNotContainsString("'stage' =>", $body);
        $this->assertStringNotContainsString("'outcome' => ProposalOutcome::Won", $body);
        $this->assertStringNotContainsString("'outcome' => ProposalOutcome::Lost", $body);
    }

    // -----------------------------------------------------------------
    // 4. ProposalResource — no editable service-owned fields
    // -----------------------------------------------------------------

    public function test_proposal_resource_has_no_editable_stage_outcome_winner_or_value_fields(): void
    {
        $contents = file_get_contents(app_path('Filament/Resources/ProposalResource.php'));

        $this->assertStringNotContainsString("Select::make('stage')", $contents);
        $this->assertStringNotContainsString("Select::make('outcome')", $contents);
        $this->assertStringNotContainsString("make('winning_version_id')", $contents);
        $this->assertStringNotContainsString("make('current_version_id')", $contents);
        $this->assertStringNotContainsString("TextInput::make('value')", $contents);
    }

    // -----------------------------------------------------------------
    // 5. Direct UI mutation
    // -----------------------------------------------------------------

    public function test_ordinary_edit_save_cannot_alter_stage_outcome_or_winner(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $proposal = $this->proposal($employee, ProposalStage::BeingPrepared);

        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->fillForm([
                'stage' => ProposalStage::CustomerAccepted->value,
                'outcome' => ProposalOutcome::Won->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $proposal->fresh();
        $this->assertSame(ProposalStage::BeingPrepared, $fresh->stage);
        $this->assertNull($fresh->outcome);
        $this->assertNull($fresh->winning_version_id);
    }

    public function test_ordinary_create_cannot_set_stage_or_outcome(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $lead = Lead::create([
            'prospect_id' => $prospect->id, 'assigned_to' => $employee->id, 'created_by' => $employee->id,
            'stage' => LeadStage::Validated, 'temperature' => 'hot', 'notes' => 'Fixture.',
        ]);

        $this->actingAs($employee);

        Livewire::test(CreateProposal::class)
            ->fillForm([
                'lead_id' => $lead->id,
                'stage' => ProposalStage::CustomerAccepted->value,
                'outcome' => ProposalOutcome::Won->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $proposal = Proposal::sole();
        $this->assertSame(ProposalStage::BeingPrepared, $proposal->stage);
        $this->assertNull($proposal->outcome);
    }

    // -----------------------------------------------------------------
    // 11. Raw DB invariant
    // -----------------------------------------------------------------

    public function test_direct_insert_won_with_null_winner_is_rejected_by_the_db(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $proposal = $this->proposal($employee);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('proposals')->where('id', $proposal->id)->update(['outcome' => 'won', 'winning_version_id' => null]);
    }

    public function test_won_with_a_valid_non_null_winner_is_accepted_by_the_db(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $proposal = $this->proposal($employee);
        $version = ProposalVersion::factory()->create(['proposal_id' => $proposal->id]);

        DB::table('proposals')->where('id', $proposal->id)->update(['outcome' => 'won', 'winning_version_id' => $version->id]);

        $this->assertSame('won', $proposal->fresh()->outcome->value);
        $this->assertSame($version->id, $proposal->fresh()->winning_version_id);
    }

    public function test_hold_with_null_winner_is_accepted_by_the_db(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $proposal = $this->proposal($employee);

        DB::table('proposals')->where('id', $proposal->id)->update(['outcome' => 'hold', 'winning_version_id' => null]);

        $this->assertSame('hold', $proposal->fresh()->outcome->value);
    }

    public function test_lost_with_null_winner_is_accepted_by_the_db(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $proposal = $this->proposal($employee);

        DB::table('proposals')->where('id', $proposal->id)->update(['outcome' => 'lost', 'winning_version_id' => null]);

        $this->assertSame('lost', $proposal->fresh()->outcome->value);
    }

    public function test_null_outcome_with_null_winner_is_accepted_by_the_db(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $proposal = $this->proposal($employee);

        DB::table('proposals')->where('id', $proposal->id)->update(['outcome' => null, 'winning_version_id' => null]);

        $this->assertNull($proposal->fresh()->outcome);
    }

    public function test_show_create_table_contains_the_expected_check(): void
    {
        $createTable = DB::selectOne('SHOW CREATE TABLE proposals')->{'Create Table'};

        $this->assertStringContainsString('proposals_won_requires_winning_version', $createTable);
        $this->assertMatchesRegularExpression("/CHECK \(.*outcome.*<>.*'won'.*or.*winning_version_id.*is not null.*\)/i", $createTable);
    }

    // -----------------------------------------------------------------
    // 12. Existing legacy Sent/Draft rows remain valid
    // -----------------------------------------------------------------

    public function test_legacy_sent_and_draft_rows_without_an_outcome_remain_valid(): void
    {
        ['employee' => $employee] = $this->hierarchy();
        $sentNoOutcome = $this->proposal($employee, ProposalStage::Sent);
        $draftNoOutcome = $this->proposal($employee, ProposalStage::BeingPrepared);

        $this->assertNotNull($sentNoOutcome->fresh());
        $this->assertNotNull($draftNoOutcome->fresh());
        $this->assertNull($sentNoOutcome->fresh()->outcome);
        $this->assertNull($draftNoOutcome->fresh()->outcome);
    }

    // -----------------------------------------------------------------
    // Codebase-shape anti-bypass sweep (Section 9 of the original gate
    // checklist: "a codebase-shape test prevents new uncontrolled writers
    // appearing", mirroring TenancyBypassUsageTest's technique).
    // -----------------------------------------------------------------

    /**
     * Every runtime (non-test, non-migration) file allowed to write a
     * literal Proposal `outcome`/`stage`/`winning_version_id` assignment.
     * Anything else matching these patterns outside this list is an
     * unexplained new writer this test must fail loudly on.
     */
    private const AUTHORIZED_WRITER_FILES = [
        'app/Services/ProposalClientResponseService.php',
        'app/Services/ProposalSendService.php',
        'app/Models/Proposal.php',
    ];

    public function test_no_unauthorized_runtime_writer_of_proposal_outcome_stage_or_winner_exists(): void
    {
        $violations = [];

        foreach (File::allFiles(app_path()) as $file) {
            $relativePath = 'app/'.$file->getRelativePathname();

            if (in_array($relativePath, self::AUTHORIZED_WRITER_FILES, true)) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            // Only flag actual WRITE-shaped occurrences (an array key
            // being assigned an enum case or the literal string values),
            // not casts()/docblocks/read-only comparisons.
            $patterns = [
                "/\\-\\>(?:update|forceFill)\\(\\s*\\[[^\\]]*'outcome'\\s*=>\\s*ProposalOutcome::(?:Won|Lost|Hold)/s",
                "/\\-\\>(?:update|forceFill)\\(\\s*\\[[^\\]]*'stage'\\s*=>\\s*ProposalStage::(?:Sent|CustomerAccepted|CustomerRejected)/s",
                "/\\-\\>(?:update|forceFill)\\(\\s*\\[[^\\]]*'winning_version_id'\\s*=>\\s*(?!null)[^,\\]]+/s",
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $contents) === 1 && Str::contains($contents, 'Proposal')) {
                    $violations[$relativePath] = $relativePath;
                }
            }
        }

        $this->assertSame(
            [],
            array_values($violations),
            'Only ProposalClientResponseService (Accepted/Revision Requested/More Time/Rejected) and '.
            'ProposalSendService (first send -> Sent) may write Proposal.outcome/stage/winning_version_id — '.
            'found an unexplained candidate writer in: '.implode(', ', $violations)
        );
    }
}
