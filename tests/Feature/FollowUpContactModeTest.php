<?php

namespace Tests\Feature;

use App\Enums\ContactMode;
use App\Enums\FollowUpStatus;
use App\Filament\Resources\FollowUpResource\Pages\CreateFollowUp;
use App\Filament\Resources\FollowUpResource\Pages\EditFollowUp;
use App\Models\FollowUp;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Contact Mode" on the Follow-Up Create/Edit form: an optional dropdown
 * (Call/Mail/Text) shown alongside "Follow Up At" — see App\Enums\ContactMode.
 * No routing/business logic keys off it, unlike CallOutcome.
 */
class FollowUpContactModeTest extends TestCase
{
    use RefreshDatabase;

    private function baseFormData(array $overrides = []): array
    {
        $prospect = Prospect::factory()->create();

        return array_merge([
            'prospect_id' => $prospect->id,
            'follow_up_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'reason' => 'Call back next week',
            'status' => FollowUpStatus::Pending->value,
        ], $overrides);
    }

    public function test_creating_a_follow_up_with_a_contact_mode_saves_it(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateFollowUp::class)
            ->fillForm($this->baseFormData(['contact_mode' => ContactMode::Text->value]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(ContactMode::Text, FollowUp::sole()->contact_mode);
    }

    public function test_creating_a_follow_up_without_a_contact_mode_is_accepted(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateFollowUp::class)
            ->fillForm($this->baseFormData())
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(FollowUp::sole()->contact_mode);
    }

    public function test_editing_a_follow_up_can_change_its_contact_mode(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $followUp = FollowUp::create([
            'prospect_id' => Prospect::factory()->create()->id,
            'user_id' => $admin->id,
            'follow_up_at' => now()->addDay(),
            'contact_mode' => ContactMode::Call,
            'reason' => 'Initial call back',
            'status' => FollowUpStatus::Pending,
        ]);

        Livewire::test(EditFollowUp::class, ['record' => $followUp->getRouteKey()])
            ->fillForm(['contact_mode' => ContactMode::Mail->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(ContactMode::Mail, $followUp->fresh()->contact_mode);
    }
}
