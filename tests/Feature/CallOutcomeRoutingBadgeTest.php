<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Filament\Resources\CallRecordResource;
use App\Filament\Resources\CallRecordResource\Pages\CreateCallRecord;
use App\Models\CallRecord;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Calls Phase 2, item 3: a small routing-destination badge in the Outcome
 * dropdown, derived entirely from CallOutcome's own existing routesTo*()
 * predicates (CallOutcome::routingBadge()) — no separate outcome-to-
 * destination mapping is introduced. Applied everywhere the Outcome Select
 * appears: CallRecordResource::callDetailsFieldsSchema() (the main Create/
 * Edit/View form, and — via formSchema(), reused verbatim, unmodified —
 * PipelineBoard's "+ Log a call"/"Record New Call" dialog) and
 * correctOutcomeAction()'s "Corrected Outcome" Select.
 */
class CallOutcomeRoutingBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        return $admin;
    }

    /** @return array<string, array{0: CallOutcome, 1: string}> */
    public static function outcomeBadgeProvider(): array
    {
        return [
            'No Answer' => [CallOutcome::NoAnswer, 'Stays at Call'],
            'Switched Off' => [CallOutcome::SwitchedOff, 'Stays at Call'],
            'Not Reachable' => [CallOutcome::NotReachable, 'Stays at Call'],
            'No Current Requirement' => [CallOutcome::NoCurrentRequirement, 'Stays at Call'],
            'Callback Requested' => [CallOutcome::CallbackRequested, '→ Follow-Up'],
            'Concerned Person Not Available' => [CallOutcome::ConcernedPersonNotAvailable, '→ Follow-Up (if date set)'],
            'Profile Requested' => [CallOutcome::ProfileRequested, '→ Follow-Up (if date set)'],
            'Appointment Set' => [CallOutcome::AppointmentSet, '→ Appointment'],
            'Requirement Identified' => [CallOutcome::RequirementIdentified, '→ Lead'],
            'Others' => [CallOutcome::Others, 'You choose next action'],
        ];
    }

    #[DataProvider('outcomeBadgeProvider')]
    public function test_routing_badge_text_matches_proposed_wording(CallOutcome $outcome, string $expectedBadge): void
    {
        $this->assertSame($expectedBadge, $outcome->routingBadge());
    }

    /** Every case must produce a non-empty badge — no silent fallthrough. */
    public function test_every_outcome_has_a_non_empty_routing_badge(): void
    {
        foreach (CallOutcome::cases() as $outcome) {
            $this->assertNotSame('', $outcome->routingBadge(), "Outcome {$outcome->value} has no routing badge.");
        }
    }

    public function test_outcome_select_options_render_the_label_and_the_badge_for_every_case(): void
    {
        $options = CallRecordResource::outcomeSelectOptions();

        foreach (CallOutcome::cases() as $outcome) {
            $this->assertArrayHasKey($outcome->value, $options);

            $html = (string) $options[$outcome->value];
            $this->assertStringContainsString($outcome->getLabel(), $html);
            $this->assertStringContainsString($outcome->routingBadge(), $html);
            // Must contain real markup (not a plain-text label), confirming
            // this is meant for ->allowHtml() rendering, not raw escaped text.
            $this->assertStringContainsString('<span', $html);
        }
    }

    public function test_main_form_outcome_select_uses_the_badge_options(): void
    {
        $outcomeSelect = collect(CallRecordResource::callDetailsFieldsSchema())
            ->first(fn ($component) => method_exists($component, 'getName') && $component->getName() === 'outcome');

        $this->assertNotNull($outcomeSelect, 'Could not find the outcome Select in callDetailsFieldsSchema().');
        $this->assertTrue($outcomeSelect->isHtmlAllowed());
        $this->assertFalse($outcomeSelect->isNative());

        $options = $outcomeSelect->getOptions();
        $this->assertStringContainsString(CallOutcome::AppointmentSet->routingBadge(), (string) $options[CallOutcome::AppointmentSet->value]);
    }

    public function test_correct_outcome_actions_select_uses_the_badge_options(): void
    {
        $admin = $this->admin();
        $prospect = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id]);
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $admin->id,
            'called_at' => now(),
            'outcome' => CallOutcome::NoAnswer->value,
        ]);

        $livewireTest = Livewire::test(\App\Filament\Resources\CallRecordResource\Pages\ListCallRecords::class);
        $livewireTest->mountTableAction('correctOutcome', $call);

        $form = $livewireTest->instance()->getMountedTableActionForm();
        $outcomeField = $form->getFlatFields()['outcome'] ?? null;

        $this->assertNotNull($outcomeField, 'Could not find the outcome field on the mounted Correct Outcome action.');
        $this->assertTrue($outcomeField->isHtmlAllowed());
        $this->assertFalse($outcomeField->isNative());

        $options = $outcomeField->getOptions();
        $this->assertStringContainsString(CallOutcome::AppointmentSet->routingBadge(), (string) $options[CallOutcome::AppointmentSet->value]);
    }

    /**
     * The dropdown must still actually filter/select correctly with the
     * new HTML-label options — same value (case->value) as before, just a
     * richer label. Already covered end-to-end by CallRoutingTest/
     * CorrectOutcomeTest/CallRecordRequiresContactDetailsTest (all still
     * green with this change), this asserts it directly against the form
     * component too.
     */
    public function test_selecting_an_outcome_still_persists_the_plain_value(): void
    {
        $admin = $this->admin();
        $prospect = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id]);

        Livewire::test(CreateCallRecord::class)
            ->fillForm([
                'prospect_id' => $prospect->id,
                'called_at' => now(),
                'outcome' => CallOutcome::NoAnswer->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $call = CallRecord::query()->where('prospect_id', $prospect->id)->sole();
        $this->assertSame(CallOutcome::NoAnswer, $call->outcome);
    }
}
