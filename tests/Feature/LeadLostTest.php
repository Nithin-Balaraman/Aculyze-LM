<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Models\Lead;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Change Request "Decision 2": Lead Lost is a designation layered on top of
 * a Lead's current stage (not a stage itself, and not a separate progression
 * path), captures lost_at_stage/lost_reason/lost_at, and must never make a
 * Lead disappear from staleness/dropout accounting incorrectly.
 */
class LeadLostTest extends TestCase
{
    use RefreshDatabase;

    private function makeLead(LeadStage $stage = LeadStage::DemoScheduledOrDone): Lead
    {
        $prospect = Prospect::factory()->create();

        return Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'stage' => $stage,
            'temperature' => 'warm',
        ]);
    }

    public function test_mark_lost_captures_stage_reason_and_timestamp_without_changing_the_stage_field(): void
    {
        $lead = $this->makeLead(LeadStage::DemoScheduledOrDone);

        $lead->markLost('Went with a competitor.');
        $lead->refresh();

        $this->assertTrue($lead->is_lost);
        $this->assertSame(LeadStage::DemoScheduledOrDone, $lead->lost_at_stage);
        $this->assertSame('Went with a competitor.', $lead->lost_reason);
        $this->assertNotNull($lead->lost_at);
        $this->assertSame(LeadStage::DemoScheduledOrDone, $lead->stage);
    }

    public function test_lost_lead_is_never_stale_even_if_stuck_for_a_long_time(): void
    {
        $lead = $this->makeLead(LeadStage::RequirementCollection);
        Lead::withoutEvents(fn () => $lead->forceFill(['stage_changed_at' => Date::now()->subDays(90)])->save());
        $lead->markLost('No budget.');
        $lead->refresh();

        $this->assertFalse($lead->isStale());
    }

    public function test_scope_stale_excludes_lost_leads(): void
    {
        $lead = $this->makeLead(LeadStage::RequirementCollection);
        Lead::withoutEvents(fn () => $lead->forceFill(['stage_changed_at' => Date::now()->subDays(90)])->save());
        $lead->markLost('No budget.');

        $this->assertSame(0, Lead::query()->stale()->count());
    }

    public function test_scope_lost_returns_only_lost_leads(): void
    {
        $lost = $this->makeLead();
        $lost->markLost('Chose a competitor.');
        $this->makeLead();

        $this->assertSame(1, Lead::query()->lost()->count());
        $this->assertTrue(Lead::query()->lost()->first()->is($lost));
    }

    public function test_marking_lost_does_not_prevent_normal_stage_progression_history_before_it(): void
    {
        $lead = $this->makeLead(LeadStage::RequirementCollection);
        $lead->update(['stage' => LeadStage::DemoScheduledOrDone]);
        $lead->update(['stage' => LeadStage::Validated, 'notes' => 'Validated in test fixture.']);
        $lead->markLost('Deal fell through after validation.');
        $lead->refresh();

        $this->assertSame(LeadStage::Validated, $lead->stage);
        $this->assertSame(LeadStage::Validated, $lead->lost_at_stage);
    }

    public function test_mark_lost_action_requires_a_reason(): void
    {
        $employee = User::factory()->create();
        $lead = Lead::create([
            'prospect_id' => Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id])->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => LeadStage::RequirementCollection,
            'temperature' => 'warm',
        ]);

        $this->actingAs($employee);

        Livewire::test(ListLeads::class)
            ->callTableAction('markLost', $lead, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);

        $this->assertFalse($lead->fresh()->is_lost);
    }

    public function test_mark_lost_action_succeeds_with_a_reason_and_is_hidden_once_already_lost(): void
    {
        $employee = User::factory()->create();
        $lead = Lead::create([
            'prospect_id' => Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id])->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => LeadStage::RequirementCollection,
            'temperature' => 'warm',
        ]);

        $this->actingAs($employee);

        Livewire::test(ListLeads::class)
            ->callTableAction('markLost', $lead, data: ['reason' => 'Budget cut.'])
            ->assertHasNoTableActionErrors();

        $lead->refresh();
        $this->assertTrue($lead->is_lost);
        $this->assertSame('Budget cut.', $lead->lost_reason);

        Livewire::test(ListLeads::class)
            ->assertTableActionHidden('markLost', $lead);
    }
}
