<?php

namespace Tests\Feature;

use App\Enums\ProposalVersionLifecycle;
use App\Enums\UserRole;
use App\Filament\Resources\ProposalResource\Pages\ManageCommercialVersion;
use App\Filament\Resources\ProposalResource\Pages\ViewCommercialVersion;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use App\Models\Prospect;
use App\Models\User;
use App\Services\ProposalPdfArtifactService;
use App\Services\ProposalReleaseService;
use App\Services\ProposalSendService;
use App\Services\ProposalVersionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 4A-3.3, Sections E/F/P/Q: the Release and Manual Send actions/
 * history wired into the real ManageCommercialVersion/ViewCommercialVersion
 * Livewire pages — not just the underlying services (already covered
 * exhaustively elsewhere).
 */
class ManageCommercialVersionReleaseSendActionsTest extends TestCase
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

    private function approvedProposal(User $employee, User $manager, User $seniorManager): Proposal
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
        $proposal->forceFill(['current_version_id' => $version->id])->save();

        app(ProposalVersionWorkflowService::class)->submit($version->fresh(), $manager);
        app(ProposalVersionWorkflowService::class)->approve($version->fresh(), $seniorManager);

        return $proposal->fresh();
    }

    private function sendKey(): string
    {
        return (string) \Illuminate\Support\Str::uuid();
    }

    public function test_employee_has_no_send_or_download_action_before_release(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);

        $this->actingAs($employee);
        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertActionHidden('recordManualSend')
            ->assertActionHidden('downloadFinalPdf');
    }

    public function test_employee_gains_released_pdf_access_after_release(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);
        app(ProposalReleaseService::class)->release($proposal->fresh()->currentVersion, $manager);

        $this->actingAs($employee);
        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertActionVisible('downloadFinalPdf')
            ->assertActionVisible('recordManualSend');
    }

    public function test_stale_release_hides_the_send_action_again(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);
        $version = $proposal->fresh()->currentVersion;
        app(ProposalReleaseService::class)->release($version, $manager);
        app(ProposalPdfArtifactService::class)->correct($version->fresh(), $manager, 'Correction.');

        $this->actingAs($employee);
        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertActionHidden('recordManualSend');
    }

    public function test_manager_sees_release_status_and_history(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);
        $version = $proposal->fresh()->currentVersion;

        $this->actingAs($manager);
        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertActionVisible('releaseForSending')
            ->assertSee('Release Status')
            ->assertSee('Not Released');

        app(ProposalReleaseService::class)->release($version->fresh(), $manager, 'Checked pricing twice before releasing.');

        // "Released — Valid" is a small, genuinely dynamic status string,
        // but assert the real Released By / Release Comment values too —
        // confirming those TextEntries resolve this exact release's own
        // data, not merely that the status computation flipped.
        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->assertSee('Released — Valid')
            ->assertSee($manager->name)
            ->assertSee('Checked pricing twice before releasing.');
    }

    public function test_manager_can_release_from_the_page(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);

        $this->actingAs($manager);
        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->callAction('releaseForSending', data: ['release_comment' => 'Go ahead.']);

        $this->assertNotNull($proposal->fresh()->currentVersion->released_at);
    }

    public function test_employee_can_record_a_manual_send_from_the_page(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);
        app(ProposalReleaseService::class)->release($proposal->fresh()->currentVersion, $manager);

        $this->actingAs($employee);
        Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()])
            ->callAction('recordManualSend', data: [
                'to_recipients' => ['client@example.com'],
                'cc_recipients' => [],
                'subject' => 'Your Proposal',
                'notes' => 'Sent via phone follow-up.',
                'sent_at' => now()->format('Y-m-d H:i:s'),
                'selected_attachment_paths' => [],
            ]);

        $this->assertSame(ProposalVersionLifecycle::Sent, $proposal->fresh()->currentVersion->lifecycle_status);
        $this->assertSame(1, \App\Models\ProposalSend::query()->count());
    }

    public function test_multiple_sends_display_as_separate_history_rows(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);
        $version = $proposal->fresh()->currentVersion;
        app(ProposalReleaseService::class)->release($version->fresh(), $manager);

        app(ProposalSendService::class)->recordManualSend($version->fresh(['proposal']), $employee, ['first@b.com'], [], 'First send subject', null, now(), [], $this->sendKey());
        app(ProposalSendService::class)->recordManualSend($version->fresh(['proposal']), $employee, ['second@b.com'], [], 'Second send subject', null, now(), [], $this->sendKey());

        $this->actingAs($manager);
        $component = Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()]);

        $this->assertSame(2, \App\Models\ProposalSend::query()->where('proposal_version_id', $version->getKey())->count());

        // Both real rows must actually render on the page, distinctly and
        // simultaneously — not just exist in the database — confirming the
        // RepeatableEntry is genuinely iterating every ProposalSend row
        // rather than collapsing to (or only ever rendering) one.
        $component->assertSee('first@b.com');
        $component->assertSee('second@b.com');
        $component->assertSee('First send subject');
        $component->assertSee('Second send subject');
    }

    public function test_send_history_is_labelled_marked_as_sent_manually_never_delivered(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);
        $version = $proposal->fresh()->currentVersion;
        app(ProposalReleaseService::class)->release($version->fresh(), $manager);
        app(ProposalSendService::class)->recordManualSend($version->fresh(['proposal']), $employee, ['a@b.com'], [], null, null, now(), [], $this->sendKey());

        $this->actingAs($manager);
        $component = Livewire::test(ManageCommercialVersion::class, ['record' => $proposal->getRouteKey()]);

        // Real recipient data must actually render (not merely the
        // section's own static description text, which happens to also
        // contain the phrase "Marked as sent manually" — a prior version of
        // this test passed on that alone without the repeatable entry
        // itself ever rendering any real row data).
        $component->assertSee('a@b.com');
        $component->assertSee('Marked as sent manually');
        $component->assertDontSee('Delivered');
    }

    public function test_historical_version_page_exposes_no_release_or_send_action(): void
    {
        ['employee' => $employee, 'manager' => $manager, 'seniorManager' => $seniorManager] = $this->hierarchy();
        $proposal = $this->approvedProposal($employee, $manager, $seniorManager);
        $version = $proposal->fresh()->currentVersion;

        $this->actingAs($manager);
        Livewire::test(ViewCommercialVersion::class, ['record' => $proposal->getRouteKey(), 'version' => $version->getKey()])
            ->assertActionDoesNotExist('releaseForSending')
            ->assertActionDoesNotExist('recordManualSend');
    }
}
