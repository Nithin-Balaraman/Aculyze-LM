<?php

namespace Tests\Feature;

use App\Enums\AppointmentStage;
use App\Enums\AppointmentStatus;
use App\Filament\Resources\AppointmentResource\Pages\ListAppointments;
use App\Models\Appointment;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Services\RescheduleService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Found during manual browser verification: the Appointment History tab's
 * `stage` badge kept showing the legacy stage label (e.g. "Appointment
 * Made") for a Rescheduled Appointment, with nothing indicating it was
 * actually historical/Rescheduled — the underlying `status` was correct,
 * only the badge's display logic never consulted it. Follow-Up's own
 * History tab groups by `status` directly so it never had this gap.
 *
 * Fixed in AppointmentResource::columns()'s `stage` column: the badge now
 * shows "Rescheduled" (and its color) whenever status is Rescheduled,
 * falling back to the normal stage label/color otherwise — so every other
 * tab/widget reusing columns() (Pending, Lost, the mini-table widget) is
 * unaffected for any non-Rescheduled record.
 */
class AppointmentHistoryBadgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_rescheduled_appointment_shows_a_rescheduled_badge_on_the_history_tab_not_the_stale_stage_label(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

            $original = Appointment::create([
                'prospect_id' => $prospect->id,
                'assigned_to' => $user->id,
                'created_by' => $user->id,
                'appointment_at' => now()->addDays(2),
                'stage' => AppointmentStage::AppointmentMade,
                'status' => AppointmentStatus::Scheduled,
            ]);

            app(RescheduleService::class)->reschedule($original, ['appointment_at' => now()->addDays(5)]);

            // Confirm the fix directly against AppointmentResource's own
            // badge-resolution logic (exactly what columns() wires up)
            // rather than scraping the full rendered page — the "stage"
            // SelectFilter's option list legitimately prints every
            // AppointmentStage label somewhere on the page regardless of
            // this row's own data, which would make a whole-page "must not
            // contain the stale label" assertion a false signal.
            $record = $original->fresh();

            $this->assertSame(
                'Rescheduled',
                \App\Filament\Resources\AppointmentResource::resolveStageBadgeLabel($record, $record->stage)
            );

            Livewire::test(ListAppointments::class)->set('activeTab', 'history')->assertSee('Rescheduled');
        });
    }

    public function test_a_normal_non_rescheduled_appointment_still_shows_its_ordinary_stage_label(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

            Appointment::create([
                'prospect_id' => $prospect->id,
                'assigned_to' => $user->id,
                'created_by' => $user->id,
                'appointment_at' => now()->addDays(2),
                'stage' => AppointmentStage::AppointmentMade,
                'status' => AppointmentStatus::Scheduled,
            ]);

            $test = Livewire::test(ListAppointments::class)->set('activeTab', 'pending');

            $test->assertSee(AppointmentStage::AppointmentMade->getLabel());
        });
    }
}
