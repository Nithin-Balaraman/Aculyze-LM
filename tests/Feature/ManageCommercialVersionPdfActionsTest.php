<?php

namespace Tests\Feature;

use App\Enums\ProposalPdfArtifactStatus;
use App\Enums\ProposalVersionLifecycle;
use App\Enums\UserRole;
use App\Filament\Resources\ProposalResource\Pages\ManageCommercialVersion;
use App\Filament\Resources\ProposalResource\Pages\ViewCommercialVersion;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalPdfArtifact;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use App\Models\Prospect;
use App\Models\User;
use App\Services\ProposalVersionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 4A-3.2: the Generate/Download/Correct final-PDF actions wired into
 * ManageCommercialVersion and ViewCommercialVersion's own read-only artifact
 * history, exercised through the real Livewire pages — not just the
 * underlying ProposalPdfArtifactService (already covered exhaustively
 * elsewhere).
 */
class ManageCommercialVersionPdfActionsTest extends TestCase
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

    private function approvedProposal(User $employee, User $manager): Proposal
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
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
        $version->forceFill(['lifecycle_status' => ProposalVersionLifecycle::Approved])->save();
        $proposal->forceFill(['current_version_id' => $version->id])->save();

        return $proposal->fresh();
    }

    public function test_manager_can_generate_final_pdf_from_the_page(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager);

        $this->actingAs($manager);
        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertActionVisible('generateFinalPdf')
            ->call('generateFinalPdfAction');

        $this->assertSame(1, ProposalPdfArtifact::query()->where('status', ProposalPdfArtifactStatus::Success)->count());
    }

    public function test_employee_never_sees_any_pdf_action_or_section(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager);

        $this->actingAs($employee);
        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertActionHidden('generateFinalPdf')
            ->assertActionHidden('downloadFinalPdf')
            ->assertActionHidden('correctFinalPdf');
    }

    public function test_generate_action_is_hidden_once_a_primary_already_exists_and_download_appears_instead(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager);

        $this->actingAs($manager);
        $component = Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->call('generateFinalPdfAction');

        $component
            ->assertActionHidden('generateFinalPdf')
            ->assertActionVisible('downloadFinalPdf')
            ->assertActionVisible('correctFinalPdf');
    }

    public function test_manager_can_correct_the_final_pdf_with_a_reason(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager);

        $this->actingAs($manager);
        $component = Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->call('generateFinalPdfAction');

        $original = ProposalPdfArtifact::query()->where('status', ProposalPdfArtifactStatus::Success)->sole();

        $component->callAction('correctFinalPdf', data: ['correction_reason' => 'Letterhead correction from the UI.']);

        $this->assertNotNull($original->fresh()->superseded_at);
        $this->assertSame(2, ProposalPdfArtifact::query()->count());
    }

    public function test_correct_action_requires_a_reason(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager);

        $this->actingAs($manager);
        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->call('generateFinalPdfAction')
            ->callAction('correctFinalPdf', data: ['correction_reason' => ''])
            ->assertHasActionErrors(['correction_reason' => 'required']);
    }

    public function test_historical_version_page_lists_pdf_artifact_history_read_only(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager);

        app(ProposalVersionWorkflowService::class); // ensure service container warm
        $this->actingAs($manager);
        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->call('generateFinalPdfAction');

        $version = $proposal->fresh()->currentVersion;

        Livewire::test(ViewCommercialVersion::class, ['record' => $proposal->getRouteKey(), 'version' => $version->getKey()])
            ->assertSee('Final PDF Artifact History')
            ->assertSee('Current Primary');
    }

    public function test_historical_version_page_exposes_no_mutating_pdf_action(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager);

        $this->actingAs($manager);
        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->call('generateFinalPdfAction');

        $version = $proposal->fresh()->currentVersion;

        Livewire::test(ViewCommercialVersion::class, ['record' => $proposal->getRouteKey(), 'version' => $version->getKey()])
            ->assertActionDoesNotExist('generateFinalPdf')
            ->assertActionDoesNotExist('correctFinalPdf');
    }
}
