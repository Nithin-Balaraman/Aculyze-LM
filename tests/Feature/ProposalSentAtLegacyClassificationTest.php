<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Filament\Resources\ProposalResource\Pages\CreateProposal;
use App\Filament\Resources\ProposalResource\Pages\EditProposal;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Prospect;
use App\Models\User;
use App\Support\Exports\ProposalExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 4A-3.6: `Proposal.sent_at` is a legacy field that predates the
 * commercial Version workflow. It is no longer written by any runtime
 * service (confirmed by inventory — ProposalSendService only ever writes
 * ProposalVersion.sent_at and ProposalSend.sent_at, never Proposal.sent_at)
 * and has no legitimate runtime dependency. Per the locked 4A-3.6 decision
 * it is retained in the schema (no column drop, no auto-backfill from
 * Version sends) but made read-only on the generic form and clearly
 * labeled as legacy wherever it's surfaced, so it can no longer be
 * manually edited to imply a send that never happened through the real
 * workflow.
 */
class ProposalSentAtLegacyClassificationTest extends TestCase
{
    use RefreshDatabase;

    private function validatedLead(User $owner): Lead
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);

        return Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'stage' => LeadStage::Validated,
            'temperature' => 'hot',
            'notes' => 'Validated in test fixture.',
        ]);
    }

    private function proposal(User $owner, ?Carbon $sentAt = null): Proposal
    {
        $lead = $this->validatedLead($owner);

        return Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $lead->prospect_id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'stage' => 'being_prepared',
            'sent_at' => $sentAt,
        ]);
    }

    public function test_the_generic_form_no_longer_exposes_an_editable_sent_at_date_picker(): void
    {
        $contents = file_get_contents(app_path('Filament/Resources/ProposalResource.php'));

        $this->assertStringNotContainsString("DatePicker::make('sent_at')", $contents);
        $this->assertStringContainsString("Placeholder::make('sent_at_display')", $contents);
    }

    public function test_creating_a_proposal_cannot_set_sent_at_via_the_form(): void
    {
        $employee = User::factory()->create();
        $lead = $this->validatedLead($employee);
        $this->actingAs($employee);

        Livewire::test(CreateProposal::class)
            ->fillForm([
                'lead_id' => $lead->id,
                'sent_at' => now()->toDateString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(Proposal::sole()->sent_at);
    }

    public function test_editing_a_proposal_cannot_change_an_existing_sent_at_via_the_form(): void
    {
        $employee = User::factory()->create();
        $original = Carbon::parse('2026-01-15');
        $proposal = $this->proposal($employee, $original);
        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->fillForm(['sent_at' => now()->toDateString()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($original->equalTo($proposal->fresh()->sent_at));
    }

    public function test_the_legacy_sent_at_placeholder_is_visible_only_when_a_value_exists(): void
    {
        $employee = User::factory()->create();
        $withValue = $this->proposal($employee, Carbon::parse('2026-01-15'));
        $withoutValue = $this->proposal($employee, null);
        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $withValue->getRouteKey()])
            ->assertSee('Sent At (Legacy)');

        Livewire::test(EditProposal::class, ['record' => $withoutValue->getRouteKey()])
            ->assertDontSee('Sent At (Legacy)');
    }

    /**
     * The authoritative send truth remains ProposalVersion.sent_at / the
     * full proposal_sends history — this legacy field's classification
     * does not change that in any way.
     */
    public function test_proposal_export_labels_the_legacy_field_explicitly(): void
    {
        $exporter = new ProposalExporter;

        $this->assertContains('Sent At (Legacy)', $exporter->headers());
        $this->assertNotContains('Sent At', $exporter->headers());
    }
}
