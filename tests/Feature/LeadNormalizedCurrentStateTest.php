<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Enums\UserRole;
use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Filament\Widgets\PipelinePulse;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 3 correction round: PipelinePulse's Active Lead count and
 * ListLeads::getTabs()'s Pending/History split previously used two
 * DIFFERENT current-state conditions — the widget combined normalized
 * status with a legacy `stage != Validated` exclusion, while ListLeads
 * itself was legacy-stage-only. Both are now driven by the single
 * normalized concept LeadStatus::isTerminalForProgression()
 * (ProposalRequired/NoCurrentProgression), so legacy `stage` no longer
 * controls either live view — it remains readable for
 * StageDropoutReport/historical export only.
 */
class LeadNormalizedCurrentStateTest extends TestCase
{
    use RefreshDatabase;

    private function widgetLeadCount(): int
    {
        $nodes = Livewire::test(PipelinePulse::class)->viewData('nodes');

        return collect($nodes)->firstWhere('key', 'lead')['count'];
    }

    private function pendingTabCount(): int
    {
        return Livewire::test(ListLeads::class)
            ->set('activeTab', 'pending')
            ->instance()
            ->getAllTableRecordsCount();
    }

    public function test_pipeline_pulse_and_list_leads_pending_both_key_off_normalized_status_alone(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        // Frozen legacy stage says "still collecting requirements" but the
        // normalized status has already reached ProposalRequired via a real
        // workflow transition (e.g. WorkflowTransitionService or the
        // Update Status action) — both consumers must treat this as done,
        // not active, purely from `status`.
        Lead::create([
            'prospect_id' => Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id])->id,
            'assigned_to' => $admin->id,
            'created_by' => $admin->id,
            'stage' => LeadStage::RequirementCollection,
            'status' => LeadStatus::ProposalRequired,
            'temperature' => 'warm',
            'notes' => 'Confirmed everything needed.',
        ]);

        $this->assertSame(0, $this->widgetLeadCount());
        $this->assertSame(0, $this->pendingTabCount());
    }

    public function test_a_frozen_validated_stage_with_a_still_active_status_is_not_incorrectly_excluded(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        // The inverse gap: legacy stage says "Validated" (which the OLD
        // stage-only ListLeads Pending tab, and the OLD dual-condition
        // PipelinePulse query, both would have wrongly excluded) but the
        // normalized status is still genuinely active (e.g. a historical
        // row whose stage was hand-edited without any real progression).
        // Frozen legacy stage must not incorrectly EXCLUDE this Lead any
        // more — only normalized status decides.
        Lead::create([
            'prospect_id' => Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id])->id,
            'assigned_to' => $admin->id,
            'created_by' => $admin->id,
            'stage' => LeadStage::Validated,
            'status' => LeadStatus::FollowUpRequired,
            'temperature' => 'warm',
            'notes' => 'Still working this one.',
        ]);

        $this->assertSame(1, $this->widgetLeadCount());
        $this->assertSame(1, $this->pendingTabCount());
    }

    /**
     * ListLeads::getTabs() calls Lead::scopeVisibleTo() (hierarchy-scoped);
     * PipelinePulse is an admin-only, organization-wide aggregate with no
     * per-actor hierarchy scoping of its own (see the widget's own class
     * docblock) — so hierarchy visibility is proven against the tab here,
     * confirming the normalized-status migration didn't disturb the
     * separate hierarchy scope it composes with.
     */
    public function test_hierarchy_visibility_is_unchanged_by_the_normalized_status_migration(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $manager = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Manager]);
            $employeeA = User::factory()->create(['organization_id' => $org->id]);
            $employeeA->update(['manager_id' => $manager->id]);
            $employeeB = User::factory()->create(['organization_id' => $org->id]);

            Lead::create([
                'prospect_id' => Prospect::factory()->create(['assigned_to' => $employeeA->id, 'created_by' => $employeeA->id])->id,
                'assigned_to' => $employeeA->id,
                'created_by' => $employeeA->id,
                'stage' => LeadStage::RequirementCollection,
                'status' => LeadStatus::RequirementCollection,
                'temperature' => 'warm',
            ]);

            Lead::create([
                'prospect_id' => Prospect::factory()->create(['assigned_to' => $employeeB->id, 'created_by' => $employeeB->id])->id,
                'assigned_to' => $employeeB->id,
                'created_by' => $employeeB->id,
                'stage' => LeadStage::RequirementCollection,
                'status' => LeadStatus::RequirementCollection,
                'temperature' => 'warm',
            ]);

            // The Manager sees their direct report's Lead but not the
            // unrelated Employee's — same hierarchy scoping as before this
            // correction, now proven against the normalized-status-driven
            // Pending tab.
            $this->actingAs($manager);
            $this->assertSame(1, $this->pendingTabCount());

            $this->actingAs($employeeB);
            $this->assertSame(1, $this->pendingTabCount());
        });
    }
}
