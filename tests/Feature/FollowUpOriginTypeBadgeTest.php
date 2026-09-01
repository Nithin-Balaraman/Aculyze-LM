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

    public function test_origin_type_label_mapping_matches_the_approved_set(): void
    {
        $this->assertSame('General', $this->invokeResolveOriginTypeLabel(null));
        $this->assertSame('Appointment', $this->invokeResolveOriginTypeLabel('appointment'));
        $this->assertSame('Demo', $this->invokeResolveOriginTypeLabel('demo'));
        $this->assertSame('Proposal', $this->invokeResolveOriginTypeLabel('proposal'));
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
