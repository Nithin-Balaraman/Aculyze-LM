<?php

namespace Tests\Feature;

use App\Filament\Resources\FollowUpResource\Pages\CreateFollowUp;
use App\Filament\Resources\FollowUpResource\Pages\EditFollowUp;
use App\Models\FollowUp;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Follow Up At" is, in practice, when the interaction actually happened —
 * not a future schedule — so it's relabeled "Followed Up At" and defaults
 * to right now on every fresh form load rather than requiring manual entry
 * or carrying over a stale value. "Next Follow-up Date" is a separate,
 * always-visible, optional field for the actual future date, added because
 * relabeling `follow_up_at` left no field for that. It is NOT the same
 * field as `new_follow_up_at` (the ephemeral, Completed-flow-only field
 * from the Outcome-routing fix, which spawns a whole new FollowUp row and
 * never persists on this one) — confirmed with the user before
 * implementing, given how similar the two names are.
 */
class FollowUpFollowedUpAtTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_form_defaults_followed_up_at_to_right_now(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $before = now();
        $test = Livewire::test(CreateFollowUp::class);
        $after = now();

        // ->seconds(false) truncates the stored value to the minute, so the
        // window has to be minute-wide, not second-wide.
        $value = Carbon::parse($test->get('data.follow_up_at'));
        $this->assertTrue($value->betweenIncluded($before->subMinute(), $after->addMinute()));
    }

    /**
     * Filament's CreateRecord::createAnother() re-fills the form via
     * $this->form->fill() (confirmed in vendor source) rather than
     * preserving prior state, so the default() closure re-runs and picks up
     * a fresh "now" — not whatever was submitted for the record just
     * created. Proved here by deliberately submitting a stale, obviously-
     * wrong Followed Up At (5 days in the past) for the first record, then
     * confirming the refilled form does NOT carry that value forward.
     */
    public function test_create_and_create_another_resets_followed_up_at_to_a_fresh_now(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create();
        $this->actingAs($admin);

        $stale = now()->subDays(5)->startOfMinute();

        $test = Livewire::test(CreateFollowUp::class)
            ->fillForm([
                'prospect_id' => $prospect->id,
                'reason' => 'First one',
                'follow_up_at' => $stale->format('Y-m-d H:i:s'),
            ])
            ->call('create', another: true)
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('follow_ups', 1);
        $this->assertTrue($stale->equalTo(FollowUp::first()->follow_up_at));

        $now = now();
        $value = Carbon::parse($test->get('data.follow_up_at'));
        $this->assertTrue($value->betweenIncluded($now->copy()->subMinute(), $now->copy()->addMinute()));
    }

    public function test_next_follow_up_date_is_optional_and_persists_when_provided(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create();
        $this->actingAs($admin);

        Livewire::test(CreateFollowUp::class)
            ->fillForm([
                'prospect_id' => $prospect->id,
                'reason' => 'Needs a callback next month',
                'next_follow_up_at' => null,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(FollowUp::first()->next_follow_up_at);

        $nextDate = now()->addMonth()->startOfMinute();

        Livewire::test(CreateFollowUp::class)
            ->fillForm([
                'prospect_id' => $prospect->id,
                'reason' => 'Needs a callback next month',
                'next_follow_up_at' => $nextDate->format('Y-m-d H:i:s'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $followUp = FollowUp::latest('id')->first();
        $this->assertTrue($nextDate->equalTo($followUp->next_follow_up_at));
    }

    /**
     * Regression guard for the two similarly-named fields' independence:
     * setting next_follow_up_at (this record's own future schedule) must
     * not be confused with new_follow_up_at (which only appears/matters
     * when completing with a Follow-Up-routing outcome, and creates a
     * separate record entirely).
     */
    public function test_next_follow_up_date_is_independent_of_the_completion_flows_new_follow_up_at(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $followUp = FollowUp::create([
            'prospect_id' => $prospect->id,
            'user_id' => $employee->id,
            'follow_up_at' => now(),
            'reason' => 'Callback later',
        ]);
        $this->actingAs($employee);

        $nextDate = now()->addWeeks(2)->startOfMinute();

        Livewire::test(EditFollowUp::class, ['record' => $followUp->getRouteKey()])
            ->fillForm(['next_follow_up_at' => $nextDate->format('Y-m-d H:i:s')])
            ->call('save')
            ->assertHasNoFormErrors();

        $followUp->refresh();
        $this->assertTrue($nextDate->equalTo($followUp->next_follow_up_at));
        // Still Pending — filling this field alone never touches Status/
        // Outcome/the Completed flow, so no Call Record is created.
        $this->assertSame(0, \App\Models\CallRecord::where('prospect_id', $followUp->prospect_id)->count());
    }
}
