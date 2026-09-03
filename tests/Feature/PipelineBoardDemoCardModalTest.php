<?php

namespace Tests\Feature;

use App\Enums\DemoMode;
use App\Enums\DemoStatus;
use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\UserRole;
use App\Filament\Pages\PipelineBoard;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Production smoke-test bug: clicking a Demo card on the Pipeline Board
 * opened a "[Company] — Demo History" modal showing only "No detail
 * available.", and its "View full record ->" link opened a second modal
 * with nothing but a Close button. Root cause: PipelineBoard::
 * cardHistoryData()'s and recordFormSchema()'s match($resource) expressions
 * had no 'demo' arm at all (falling through to the empty default), and
 * DemoResource had no extracted formSchema() for recordFormSchema() to
 * call even if it had been wired in. Every other resource (Call/Follow-up/
 * Appointment/Lead/Proposal) already worked correctly before this fix.
 */
class PipelineBoardDemoCardModalTest extends TestCase
{
    use RefreshDatabase;

    private function makeDemo(User $user, array $overrides = []): Demo
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $user->id,
            'created_by' => $user->id,
            'stage' => LeadStage::RequirementCollection,
            'status' => LeadStatus::RequirementConfirmed,
            'temperature' => LeadTemperature::Warm,
            'notes' => 'Confirmed requirement.',
        ]);

        $demo = Demo::factory()->create(array_merge([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $user->id,
            'created_by' => $user->id,
            'mode' => DemoMode::Online,
            'meeting_link' => 'https://meet.example.com/demo',
            'location' => null,
            'demo_at' => now()->addDays(2),
            'status' => DemoStatus::Scheduled,
            'product_service' => 'Aculyze CRM',
            'notes' => 'Interested in the reporting module.',
        ], $overrides));

        // origin_type/origin_id are deliberately not mass-assignable (see
        // Demo::$fillable) — every real creation path sets them via
        // forceFill(), so tests must too (mirrors DemoModelTest's own
        // pattern for the same columns).
        $demo->forceFill(['origin_type' => 'lead', 'origin_id' => $lead->id])->save();

        return $demo->fresh();
    }

    public function test_demo_card_history_modal_shows_real_detail_instead_of_no_detail_available(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Employee]);
            $this->actingAs($user);
            $demo = $this->makeDemo($user);

            $html = Livewire::test(PipelineBoard::class)
                ->mountAction('cardHistory', ['resource' => 'demo', 'id' => $demo->id])
                ->html();

            $this->assertStringNotContainsString('No detail available.', $html);
            $this->assertStringContainsString('Aculyze CRM', $html);
            $this->assertStringContainsString('Interested in the reporting module.', $html);
            $this->assertStringContainsString(DemoMode::Online->getLabel(), $html);
            $this->assertStringContainsString($demo->demo_at->format('d M Y'), $html);
            // Lineage: this Demo's origin_type/origin_id points back to its Lead.
            $this->assertStringContainsString('Created from Lead #'.$demo->lead_id, $html);
        });
    }

    public function test_demo_view_full_record_modal_renders_the_real_demo_form_not_just_a_close_button(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Employee]);
            $this->actingAs($user);
            $demo = $this->makeDemo($user, ['product_service' => 'Aculyze Analytics Suite']);

            // Mirrors the modal's own "View full record ->" button, which
            // swaps the cardHistory modal for viewRecord via
            // $wire.replaceMountedAction() rather than a page navigation —
            // see pipeline-board-history-modal.blade.php's own docblock:
            // nothing on this page ever navigates away from the board, for
            // any resource, by design.
            $test = Livewire::test(PipelineBoard::class)
                ->mountAction('viewRecord', ['resource' => 'demo', 'id' => $demo->id]);
            $html = $test->html();

            // The bug report's exact symptom: before the fix,
            // recordFormSchema('demo') returned [] (no DemoResource::
            // formSchema() existed for it to call), so this modal rendered
            // no fields at all — just the Close button. These field labels
            // proves the real DemoResource form now renders here.
            $this->assertStringContainsString('Product / Service', $html);
            $this->assertStringContainsString('Demo At', $html);
            $this->assertStringContainsString('Mode', $html);

            // Filament renders a disabled TextInput's value client-side via
            // Livewire/Alpine hydration, not as a static HTML `value=`
            // attribute, so the real content is verified against the
            // mounted action's actual filled data instead of scraping
            // rendered markup for it — this is what recordFillData()
            // populated the (previously nonexistent) form with.
            $this->assertSame('Aculyze Analytics Suite', $test->instance()->mountedActionsData[0]['product_service'] ?? null);
        });
    }

    public function test_demo_lineage_reports_a_repeat_demo_and_a_generated_follow_up(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Employee]);
            $this->actingAs($user);
            $demo = $this->makeDemo($user);

            $repeatDemo = Demo::factory()->create([
                'lead_id' => $demo->lead_id,
                'prospect_id' => $demo->prospect_id,
                'assigned_to' => $user->id,
                'created_by' => $user->id,
                'mode' => DemoMode::Online,
                'meeting_link' => 'https://meet.example.com/again',
            ]);
            $repeatDemo->forceFill(['origin_type' => 'demo', 'origin_id' => $demo->id])->save();

            $followUp = \App\Models\FollowUp::create([
                'prospect_id' => $demo->prospect_id,
                'user_id' => $user->id,
                'follow_up_at' => now()->addDay(),
                'reason' => 'More time needed',
                'status' => 'pending',
            ]);
            $followUp->forceFill(['origin_type' => 'demo', 'origin_id' => $demo->id])->save();

            $html = Livewire::test(PipelineBoard::class)
                ->mountAction('cardHistory', ['resource' => 'demo', 'id' => $demo->id])
                ->html();

            $this->assertStringContainsString('Recording this outcome created Demo #'.$repeatDemo->id, $html);
            $this->assertStringContainsString('Recording this outcome created Follow-up #'.$followUp->id, $html);
        });
    }
}
