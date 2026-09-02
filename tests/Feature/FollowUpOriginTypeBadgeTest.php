<?php

namespace Tests\Feature;

use App\Filament\Resources\FollowUpResource;
use App\Filament\Resources\FollowUpResource\Pages\ListFollowUps;
use App\Models\FollowUp;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * UI-only: a small type indicator under the Company name on the Follow-Ups
 * table, so multiple Follow-Ups for the same Company are distinguishable
 * at a glance. Driven entirely by the existing `origin_type` column — no
 * new field, no new column, never inferred from legacy stage. Historical/
 * plain call-routed Follow-Ups (origin_type = null) render "General"
 * rather than omitting the line or erroring.
 */
class FollowUpOriginTypeBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function invokeResolveOriginTypeLabel(?string $originType): ?string
    {
        $method = new \ReflectionMethod(FollowUpResource::class, 'resolveOriginTypeLabel');
        $method->setAccessible(true);

        return $method->invoke(null, $originType);
    }

    private function invokeResolveOriginTypeColor(?string $originType): string
    {
        $method = new \ReflectionMethod(FollowUpResource::class, 'resolveOriginTypeColor');
        $method->setAccessible(true);

        return $method->invoke(null, $originType);
    }

    public function test_origin_type_label_mapping_matches_the_approved_set(): void
    {
        $this->assertSame('General', $this->invokeResolveOriginTypeLabel(null));
        $this->assertSame('Appointment', $this->invokeResolveOriginTypeLabel('appointment'));
        $this->assertSame('Demo', $this->invokeResolveOriginTypeLabel('demo'));
        $this->assertSame('Proposal', $this->invokeResolveOriginTypeLabel('proposal'));
    }

    /**
     * Each type gets its own stock Filament badge color, and none of them
     * is 'warning' — the color FollowUpStatus::getColor() uses for
     * Pending, the most commonly viewed Status value, so a Demo-origin
     * Follow-Up on the Pending tab never shows two amber badges in the
     * same row.
     */
    public function test_origin_type_color_mapping_gives_each_type_a_distinct_stock_color(): void
    {
        $this->assertSame('gray', $this->invokeResolveOriginTypeColor(null));
        $this->assertSame('info', $this->invokeResolveOriginTypeColor('appointment'));
        $this->assertSame('primary', $this->invokeResolveOriginTypeColor('demo'));
        $this->assertSame('success', $this->invokeResolveOriginTypeColor('proposal'));

        $this->assertNotSame('warning', $this->invokeResolveOriginTypeColor(null));
        $this->assertNotSame('warning', $this->invokeResolveOriginTypeColor('appointment'));
        $this->assertNotSame('warning', $this->invokeResolveOriginTypeColor('demo'));
        $this->assertNotSame('warning', $this->invokeResolveOriginTypeColor('proposal'));
    }

    /**
     * "Lead" and "Proposal Revision" were explicitly dropped — no code
     * path in this app ever writes those origin_type values, and any other
     * unrecognized value must omit the badge rather than fabricate a label.
     */
    public function test_unrecognized_origin_type_omits_the_badge_rather_than_fabricating_a_label(): void
    {
        $this->assertNull($this->invokeResolveOriginTypeLabel('lead'));
        $this->assertNull($this->invokeResolveOriginTypeLabel('proposal_revision'));
        $this->assertNull($this->invokeResolveOriginTypeLabel('something_unexpected'));
    }

    private function makeFollowUp(User $user, ?string $originType, ?int $originId = null): FollowUp
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

        $followUp = FollowUp::create([
            'prospect_id' => $prospect->id,
            'user_id' => $user->id,
            'follow_up_at' => now()->addDay(),
            'reason' => 'Test follow-up',
            'status' => 'pending',
        ]);

        if ($originType !== null) {
            $followUp->forceFill(['origin_type' => $originType, 'origin_id' => $originId ?? $followUp->id])->save();
        }

        return $followUp;
    }

    public function test_the_company_column_renders_general_for_a_plain_call_routed_follow_up(): void
    {
        $user = User::factory()->create();
        $this->makeFollowUp($user, null);
        $this->actingAs($user);

        Livewire::test(ListFollowUps::class)->assertSee('General');
    }

    /**
     * Styling proof: each type's badge must be the real <x-filament::badge>
     * pill component (same shape/size markup the Status column's ->badge()
     * renders), colored per resolveOriginTypeColor() so every type is
     * visually distinguishable and none matches Status's 'warning'.
     */
    public function test_each_origin_type_renders_as_a_real_filament_badge_pill_in_its_own_color(): void
    {
        $user = User::factory()->create();
        $this->makeFollowUp($user, null);
        $this->makeFollowUp($user, 'appointment');
        $this->makeFollowUp($user, 'demo');
        $this->makeFollowUp($user, 'proposal');
        $this->actingAs($user);

        $html = Livewire::test(ListFollowUps::class)->html();

        foreach ([
            'General' => 'gray',
            'Appointment' => 'info',
            'Demo' => 'primary',
            'Proposal' => 'success',
        ] as $label => $color) {
            $this->assertMatchesRegularExpression(
                "/fi-badge[^\"]*fi-color-{$color}\"[^>]*>\\s*<span class=\"grid\">\\s*<span class=\"truncate\">\\s*{$label}/s",
                $html,
                "Expected the {$label} badge to render with fi-color-{$color}.",
            );
        }
    }

    /**
     * Sizing proof: the badge must sit inside an inline-flex/w-max
     * container so it always shrinks to fit its own text — <x-filament::
     * badge> itself is `display:flex` with no width constraint, so without
     * this wrapper it silently stretches to whatever width the row's other
     * content leaves available, rendering inconsistently wide from row to
     * row instead of a compact pill like the Status badge.
     */
    public function test_the_badge_wrapper_is_sized_to_its_own_content_not_the_row(): void
    {
        $user = User::factory()->create();
        $this->makeFollowUp($user, 'appointment');
        $this->actingAs($user);

        $html = Livewire::test(ListFollowUps::class)->html();

        $this->assertMatchesRegularExpression(
            '/<div class="mt-1 inline-flex w-max">\s*<span[^>]*fi-badge/s',
            $html,
        );
    }

    public function test_the_company_column_renders_the_correct_badge_for_each_real_origin(): void
    {
        $user = User::factory()->create();
        $this->makeFollowUp($user, 'appointment');
        $this->makeFollowUp($user, 'demo');
        $this->makeFollowUp($user, 'proposal');
        $this->actingAs($user);

        Livewire::test(ListFollowUps::class)
            ->assertSee('Appointment')
            ->assertSee('Demo')
            ->assertSee('Proposal');
    }
}
