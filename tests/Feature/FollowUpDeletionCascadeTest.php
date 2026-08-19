<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Enums\FollowUpStatus;
use App\Filament\Resources\FollowUpResource\Pages\EditFollowUp;
use App\Filament\Resources\FollowUpResource\Pages\ListFollowUps;
use App\Models\CallRecord;
use App\Models\FollowUp;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Deletion Cascade fix: a completed Follow-Up's generated Call Record
 * (call_records.follow_up_id — see CallRecord::scopeDirectlyLogged()) has
 * zero visibility anywhere else in the app, so it shouldn't block deleting
 * the Follow-Up unless it has real downstream dependents of its own (a
 * routed Follow-Up/Appointment/Lead). See FollowUp::
 * deleteHarmlessGeneratedCallRecord().
 *
 * Also covers the Edit page's Delete button, which previously had neither
 * the DeletionGuard ->before() hook (raw-throwing a 500 instead of the
 * friendly message) nor the row-level action's admin-only ->visible().
 */
class FollowUpDeletionCascadeTest extends TestCase
{
    use RefreshDatabase;

    private function makeFollowUp(User $owner): FollowUp
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $owner->id,
            'called_at' => now(),
            'outcome' => CallOutcome::NoAnswer,
        ]);

        return $call->fresh()->followUp;
    }

    /**
     * Mirrors FollowUpResource's 'completed' table action exactly — a
     * direct model-level equivalent of what that Filament action does, so
     * these tests don't depend on Livewire form-filling mechanics.
     */
    private function completeFollowUp(FollowUp $followUp, CallOutcome $outcome, string $notes = 'Reached them this time.'): CallRecord
    {
        $generated = null;

        DB::transaction(function () use ($followUp, $outcome, $notes, &$generated) {
            $generated = CallRecord::create([
                'prospect_id' => $followUp->prospect_id,
                'user_id' => $followUp->user_id,
                'called_at' => now(),
                'outcome' => $outcome,
                'notes' => $notes,
                'follow_up_id' => $followUp->id,
            ]);

            $followUp->update(['status' => FollowUpStatus::Completed]);
        });

        return $generated->fresh();
    }

    public function test_deleting_a_follow_up_cascades_its_harmless_generated_call_record(): void
    {
        $admin = User::factory()->admin()->create();
        $followUp = $this->makeFollowUp($admin);

        // FutureOpportunity routes nowhere — no Follow-Up/Appointment/Lead
        // is spawned, so the generated Call Record is a pure byproduct.
        $generated = $this->completeFollowUp($followUp, CallOutcome::FutureOpportunity, 'No current requirement.');
        $originatingCall = $followUp->callRecord;

        $this->actingAs($admin);

        // Completed Follow-Ups only appear once grouped under History/Lost
        // — the default Pending tab filters the table query down to
        // status=Pending, so the row action couldn't resolve this record
        // at all without switching tabs first (see ListFollowUps).
        Livewire::test(ListFollowUps::class)
            ->set('activeTab', 'history')
            ->callTableAction('delete', $followUp)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('follow_ups', ['id' => $followUp->id]);
        $this->assertDatabaseMissing('call_records', ['id' => $generated->id]);

        // The originating call (the one that created this Follow-Up in the
        // first place) is a different record entirely — untouched.
        $this->assertDatabaseHas('call_records', ['id' => $originatingCall->id]);
    }

    public function test_deleting_a_follow_up_is_still_blocked_when_its_generated_call_record_routed_downstream(): void
    {
        $admin = User::factory()->admin()->create();
        $followUp = $this->makeFollowUp($admin);

        // RequirementIdentified routes to both an Appointment and a Lead —
        // the generated Call Record is no longer a harmless byproduct.
        $generated = $this->completeFollowUp($followUp, CallOutcome::RequirementIdentified, 'Ready to move forward.');

        $this->actingAs($admin);

        Livewire::test(ListFollowUps::class)
            ->set('activeTab', 'history')
            ->callTableAction('delete', $followUp)
            ->assertTableActionHalted('delete')
            ->assertNotified("Can't delete this follow-up");

        $this->assertDatabaseHas('follow_ups', ['id' => $followUp->id]);
        $this->assertDatabaseHas('call_records', ['id' => $generated->id]);
        $this->assertDatabaseHas('leads', ['prospect_id' => $followUp->prospect_id]);
    }

    public function test_edit_page_delete_shows_the_guard_message_instead_of_a_500_for_a_real_blocker(): void
    {
        $admin = User::factory()->admin()->create();
        $followUp = $this->makeFollowUp($admin);
        $this->completeFollowUp($followUp, CallOutcome::RequirementIdentified, 'Ready to move forward.');

        $this->actingAs($admin);

        Livewire::test(EditFollowUp::class, ['record' => $followUp->getRouteKey()])
            ->callAction('delete')
            ->assertActionHalted('delete')
            ->assertNotified("Can't delete this follow-up");

        $this->assertDatabaseHas('follow_ups', ['id' => $followUp->id]);
    }

    public function test_edit_page_delete_cascades_a_harmless_generated_call_record_too(): void
    {
        $admin = User::factory()->admin()->create();
        $followUp = $this->makeFollowUp($admin);
        $generated = $this->completeFollowUp($followUp, CallOutcome::FutureOpportunity, 'No current requirement.');

        $this->actingAs($admin);

        Livewire::test(EditFollowUp::class, ['record' => $followUp->getRouteKey()])
            ->callAction('delete');

        $this->assertDatabaseMissing('follow_ups', ['id' => $followUp->id]);
        $this->assertDatabaseMissing('call_records', ['id' => $generated->id]);
    }

    public function test_edit_page_delete_button_is_hidden_from_non_admins(): void
    {
        $employee = User::factory()->create();
        $followUp = $this->makeFollowUp($employee);

        $this->actingAs($employee);

        Livewire::test(EditFollowUp::class, ['record' => $followUp->getRouteKey()])
            ->assertActionHidden('delete');
    }

    public function test_edit_page_delete_button_is_visible_to_admins(): void
    {
        $admin = User::factory()->admin()->create();
        $followUp = $this->makeFollowUp($admin);

        $this->actingAs($admin);

        Livewire::test(EditFollowUp::class, ['record' => $followUp->getRouteKey()])
            ->assertActionVisible('delete');
    }
}
