<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Filament\Resources\CallRecordResource\Pages\ViewCallRecord;
use App\Filament\Resources\ProspectResource;
use App\Models\CallRecord;
use App\Models\Prospect;
use App\Models\User;
use App\Services\CallRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Calls Phase 3: the View page now has a real infolist (ViewCallRecord::
 * infolist()) instead of Filament's default fallback — rendering the Edit
 * form's own schema, disabled (see ViewCallRecord's own docblock for the
 * exact hasInfolist()/fillForm() mechanism this replaces). Baseline,
 * captured directly against the pre-fix page before writing this file: 70
 * occurrences of the string "disabled", 5 <input> tags, 2 <select> tags, 1
 * <textarea> tag. Every one of those must be gone now.
 */
class CallRecordViewPageInfolistTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        return $admin;
    }

    public function test_no_disabled_form_controls_remain_on_the_view_page(): void
    {
        $admin = $this->admin();
        $prospect = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id]);
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $admin->id,
            'called_at' => now(),
            'outcome' => CallOutcome::CallbackRequested->value,
            'follow_up_at' => now()->addDay(),
            'contact_person_spoken_to' => 'Jane Doe',
            'designation' => 'Manager',
            'phone_called' => '9999999999',
            'notes' => 'Will call back tomorrow.',
        ]);

        $html = Livewire::test(ViewCallRecord::class, ['record' => $call->getRouteKey()])->html();

        $this->assertStringNotContainsString('disabled', $html);
        $this->assertStringNotContainsString('<input', $html);
        $this->assertStringNotContainsString('<select', $html);
        $this->assertStringNotContainsString('<textarea', $html);
    }

    public function test_a_plain_call_shows_every_field_in_the_specified_order_with_the_right_values(): void
    {
        $admin = $this->admin();
        $prospect = Prospect::factory()->create([
            'assigned_to' => $admin->id,
            'created_by' => $admin->id,
            'company_name' => 'Acme Textiles',
        ]);
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $admin->id,
            'called_at' => now(),
            'outcome' => CallOutcome::CallbackRequested->value,
            'follow_up_at' => now()->addDay(),
            'contact_person_spoken_to' => 'Jane Doe',
            'designation' => 'Procurement Head',
            'phone_called' => '9998887777',
            'notes' => 'Will call back tomorrow.',
        ]);

        $html = Livewire::test(ViewCallRecord::class, ['record' => $call->getRouteKey()])->html();

        foreach ([
            'Acme Textiles',
            $admin->name,
            CallOutcome::CallbackRequested->getLabel(),
            'Jane Doe',
            'Procurement Head',
            '9998887777',
            'Will call back tomorrow.',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html, "Expected to see \"{$expected}\" on the View page.");
        }

        // Field order: Company appears before Called At, which appears
        // before Called By, before Outcome, before Contact Person, before
        // Follow-up At.
        $positions = [
            'Acme Textiles' => strpos($html, 'Acme Textiles'),
            'Called At' => strpos($html, 'Called At'),
            'Called By' => strpos($html, 'Called By'),
            'Outcome' => strpos($html, 'Outcome'),
            'Contact Person' => strpos($html, 'Contact Person'),
            'Follow Up At' => strpos($html, 'Follow Up At'),
        ];

        foreach ($positions as $label => $position) {
            $this->assertNotFalse($position, "Expected to find the \"{$label}\" label in the HTML.");
        }

        $this->assertLessThan($positions['Called At'], $positions['Acme Textiles']);
        $this->assertLessThan($positions['Called By'], $positions['Called At']);
        $this->assertLessThan($positions['Outcome'], $positions['Called By']);
        $this->assertLessThan($positions['Contact Person'], $positions['Outcome']);
        $this->assertLessThan($positions['Follow Up At'], $positions['Contact Person']);
    }

    public function test_empty_optional_values_show_an_em_dash(): void
    {
        $admin = $this->admin();
        $prospect = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id]);
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $admin->id,
            'called_at' => now(),
            'outcome' => CallOutcome::NoAnswer->value,
        ]);

        $html = Livewire::test(ViewCallRecord::class, ['record' => $call->getRouteKey()])->html();

        // No Answer requires none of contact_person_spoken_to/designation/
        // phone_called/follow_up_at/appointment_at/notes — every one of
        // these should render the placeholder.
        $this->assertGreaterThanOrEqual(6, substr_count($html, '—'), 'Expected at least 6 placeholder dashes for the empty optional fields.');
    }

    public function test_next_action_only_shows_for_outcome_others(): void
    {
        $admin = $this->admin();
        $prospect = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id]);

        $othersCall = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $admin->id,
            'called_at' => now(),
            'outcome' => CallOutcome::Others->value,
            'next_action' => 'no_further_action',
            'notes' => 'Not interested right now.',
        ]);

        $plainCall = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $admin->id,
            'called_at' => now(),
            'outcome' => CallOutcome::NoAnswer->value,
        ]);

        $othersHtml = Livewire::test(ViewCallRecord::class, ['record' => $othersCall->getRouteKey()])->html();
        $plainHtml = Livewire::test(ViewCallRecord::class, ['record' => $plainCall->getRouteKey()])->html();

        $this->assertStringContainsString('Next Action', $othersHtml);
        $this->assertStringNotContainsString('Next Action', $plainHtml);
    }

    public function test_profile_sent_section_only_shows_for_outcome_profile_requested(): void
    {
        $admin = $this->admin();
        $prospect = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id]);

        $profileCall = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $admin->id,
            'called_at' => now(),
            'outcome' => CallOutcome::ProfileRequested->value,
            'contact_person_spoken_to' => 'Jane Doe',
            'designation' => 'Manager',
            'phone_called' => '9999999999',
            'profile_sent_status' => 'sent',
            'profile_sent_mode' => 'email',
            'profile_sent_at' => now(),
            'notes' => 'Requested a profile via email.',
        ]);

        $plainCall = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $admin->id,
            'called_at' => now(),
            'outcome' => CallOutcome::NoAnswer->value,
        ]);

        $profileHtml = Livewire::test(ViewCallRecord::class, ['record' => $profileCall->getRouteKey()])->html();
        $plainHtml = Livewire::test(ViewCallRecord::class, ['record' => $plainCall->getRouteKey()])->html();

        $this->assertStringContainsString('Profile Sent', $profileHtml);
        $this->assertStringNotContainsString('Profile Sent', $plainHtml);
    }

    /** Mirrors ViewProspect::companyDetailsSection()'s own equivalent test — same mechanism, same field-level assertion. */
    public function test_notes_preserves_line_breaks_and_applies_to_a_multi_line_value(): void
    {
        $admin = $this->admin();
        $prospect = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id]);
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $admin->id,
            'called_at' => now(),
            'outcome' => CallOutcome::Others->value,
            'next_action' => 'no_further_action',
            'notes' => "Line one\nLine two\nLine three",
        ]);

        $response = $this->get(\App\Filament\Resources\CallRecordResource::getUrl('view', ['record' => $call]));

        $response->assertSee('white-space: pre-line', false);
        $response->assertSee('Line one');
        $response->assertSee('Line two');
        $response->assertSee('Line three');
    }

    public function test_correction_and_flag_fields_only_show_once_actually_set(): void
    {
        $admin = $this->admin();
        $prospect = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id]);

        $untouchedCall = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $admin->id,
            'called_at' => now(),
            'outcome' => CallOutcome::NoAnswer->value,
        ]);

        $untouchedHtml = Livewire::test(ViewCallRecord::class, ['record' => $untouchedCall->getRouteKey()])->html();
        $this->assertStringNotContainsString('Correction Reason', $untouchedHtml);
        $this->assertStringNotContainsString('Flag Reason', $untouchedHtml);

        $correctedCall = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $admin->id,
            'called_at' => now(),
            'outcome' => CallOutcome::NoAnswer->value,
        ]);
        app(CallRoutingService::class)->correctOutcome($correctedCall, CallOutcome::SwitchedOff, 'Was actually switched off.');

        $correctedHtml = Livewire::test(ViewCallRecord::class, ['record' => $correctedCall->getRouteKey()])->html();
        $this->assertStringContainsString('Correction Reason', $correctedHtml);
        $this->assertStringContainsString('Was actually switched off.', $correctedHtml);
        $this->assertStringContainsString('Outcome Corrected At', $correctedHtml);
        $this->assertStringNotContainsString('Flag Reason', $correctedHtml);

        $flaggedCall = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $admin->id,
            'called_at' => now(),
            'outcome' => CallOutcome::RequirementIdentified->value,
            'contact_person_spoken_to' => 'Jane Doe',
            'designation' => 'Manager',
            'phone_called' => '9999999999',
            'notes' => 'Needs a proposal.',
        ]);
        $flaggedCall->forceFill(['flagged_incorrect_at' => now(), 'flag_reason' => 'Wrong outcome picked.'])->save();

        $flaggedHtml = Livewire::test(ViewCallRecord::class, ['record' => $flaggedCall->getRouteKey()])->html();
        $this->assertStringContainsString('Flag Reason', $flaggedHtml);
        $this->assertStringContainsString('Wrong outcome picked.', $flaggedHtml);
        $this->assertStringContainsString('Flagged At', $flaggedHtml);
        $this->assertStringNotContainsString('Correction Reason', $flaggedHtml);
    }

    public function test_header_actions_are_unchanged(): void
    {
        $admin = $this->admin();
        $prospect = Prospect::factory()->create(['assigned_to' => $admin->id, 'created_by' => $admin->id]);
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $admin->id,
            'called_at' => now(),
            'outcome' => CallOutcome::NoAnswer->value,
        ]);

        Livewire::test(ViewCallRecord::class, ['record' => $call->getRouteKey()])
            ->assertActionVisible('back')
            ->assertActionVisible('edit')
            ->assertActionHasColor('back', ProspectResource::BACK_BUTTON_COLOR);
    }
}
