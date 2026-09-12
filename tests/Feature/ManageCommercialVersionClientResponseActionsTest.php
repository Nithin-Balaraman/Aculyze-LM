<?php

namespace Tests\Feature;

use App\Enums\ProposalOutcome;
use App\Enums\ProposalVersionLifecycle;
use App\Enums\UserRole;
use App\Filament\Resources\ProposalResource\Pages\ManageCommercialVersion;
use App\Filament\Resources\ProposalResource\Pages\ViewCommercialVersion;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\Prospect;
use App\Models\User;
use App\Services\ProposalClientResponseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 4A-3.4, Sections P/Q: the Record Client Response action and its
 * read-only history, exercised through the real Livewire pages.
 */
class ManageCommercialVersionClientResponseActionsTest extends TestCase
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

    public function test_manager_can_record_accepted_from_the_page(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);

        $this->actingAs($manager);
        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertActionVisible('recordClientResponse')
            ->callAction('recordClientResponse', data: [
                'proposal_version_id' => $version->getKey(),
                'response_type' => 'accepted',
                'accepted_notes' => 'Signed off.',
            ]);

        $this->assertSame(ProposalOutcome::Won, $proposal->fresh()->outcome);
    }

    public function test_response_history_and_outcome_shown_after_recording(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);
        app(ProposalClientResponseService::class)->recordAccepted($version->fresh(), $manager, null, (string) Str::uuid());

        $this->actingAs($manager);
        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertSee('Client Response History')
            ->assertSee('Won');
    }

    public function test_recorded_by_and_version_responded_to_shown_in_history(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);
        app(ProposalClientResponseService::class)->recordRejected($version->fresh(), $manager, 'Went silent.', null, (string) Str::uuid());

        $this->actingAs($manager);
        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertSee($manager->name)
            ->assertSee('Went silent.');
    }

    public function test_resulting_draft_and_follow_up_links_shown_in_history(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);
        app(ProposalClientResponseService::class)->recordRevisionRequested($version->fresh(), $manager, 'Change pricing', null, (string) Str::uuid());

        $this->actingAs($manager);
        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertSee('Resulting Draft')
            ->assertSee('V2');
    }

    public function test_historical_version_page_shows_responses_but_exposes_no_action(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);
        app(ProposalClientResponseService::class)->recordRevisionRequested($version->fresh(), $manager, 'Change pricing exact wording.', null, (string) Str::uuid());

        $this->actingAs($manager);
        Livewire::test(ViewCommercialVersion::class, ['record' => $proposal->getRouteKey(), 'version' => $version->getKey()])
            ->assertActionDoesNotExist('recordClientResponse')
            ->assertSee('Client Responses to This Version')
            // Real row data, not merely the section title — this relies on
            // ProposalVersion::clientResponses() being a genuine Eloquent
            // relation (unlike the page-method-driven repeatables on
            // ManageCommercialVersion, which need an explicit ->state()).
            ->assertSee('Change pricing exact wording.');
    }

    public function test_action_hidden_once_proposal_is_terminal(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        [$proposal, $version] = $this->sentProposal($employee);
        app(ProposalClientResponseService::class)->recordAccepted($version->fresh(), $manager, null, (string) Str::uuid());

        $this->actingAs($manager);
        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertActionHidden('recordClientResponse');
    }
}
