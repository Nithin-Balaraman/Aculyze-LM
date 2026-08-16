<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\EditProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Self-service "change your own password" on the Profile page — a distinct
 * flow from Admin setting *someone else's* password via Employees -> edit
 * (UserResource), which this doesn't touch. Works identically for Admin and
 * Employee accounts alike, since it's tied to "who's logged in", not role.
 */
class ProfilePasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_change_their_own_password_with_the_correct_current_password(): void
    {
        $employee = User::factory()->create(['password' => Hash::make('old-password')]);
        $this->actingAs($employee);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'current_password' => 'old-password',
                'password' => 'new-password',
                'passwordConfirmation' => 'new-password',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('new-password', $employee->refresh()->password));
    }

    public function test_admin_can_change_their_own_password_the_same_way(): void
    {
        $admin = User::factory()->admin()->create(['password' => Hash::make('old-password')]);
        $this->actingAs($admin);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'current_password' => 'old-password',
                'password' => 'new-password',
                'passwordConfirmation' => 'new-password',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('new-password', $admin->refresh()->password));
    }

    public function test_wrong_current_password_is_rejected_and_does_not_change_the_password(): void
    {
        $employee = User::factory()->create(['password' => Hash::make('old-password')]);
        $this->actingAs($employee);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'current_password' => 'totally-wrong',
                'password' => 'new-password',
                'passwordConfirmation' => 'new-password',
            ])
            ->call('save')
            ->assertHasFormErrors(['current_password']);

        $this->assertTrue(Hash::check('old-password', $employee->refresh()->password));
    }

    public function test_current_password_is_required_when_changing_the_password(): void
    {
        $employee = User::factory()->create(['password' => Hash::make('old-password')]);
        $this->actingAs($employee);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'current_password' => '',
                'password' => 'new-password',
                'passwordConfirmation' => 'new-password',
            ])
            ->call('save')
            ->assertHasFormErrors(['current_password']);

        $this->assertTrue(Hash::check('old-password', $employee->refresh()->password));
    }

    public function test_new_password_and_confirmation_must_match(): void
    {
        $employee = User::factory()->create(['password' => Hash::make('old-password')]);
        $this->actingAs($employee);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'current_password' => 'old-password',
                'password' => 'new-password',
                'passwordConfirmation' => 'does-not-match',
            ])
            ->call('save')
            ->assertHasFormErrors(['password']);

        $this->assertTrue(Hash::check('old-password', $employee->refresh()->password));
    }

    public function test_leaving_all_password_fields_blank_keeps_the_current_password_and_saves_successfully(): void
    {
        $employee = User::factory()->create(['password' => Hash::make('old-password')]);
        $this->actingAs($employee);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'current_password' => '',
                'password' => '',
                'passwordConfirmation' => '',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('old-password', $employee->refresh()->password));
    }

    public function test_current_password_field_is_cleared_from_form_state_after_a_successful_change(): void
    {
        $employee = User::factory()->create(['password' => Hash::make('old-password')]);
        $this->actingAs($employee);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'current_password' => 'old-password',
                'password' => 'new-password',
                'passwordConfirmation' => 'new-password',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSet('data.current_password', null);
    }

    public function test_admin_managed_password_reset_from_the_employees_screen_is_unaffected(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create(['password' => Hash::make('old-password')]);
        $this->actingAs($admin);

        Livewire::test(\App\Filament\Resources\UserResource\Pages\EditUser::class, ['record' => $employee->getRouteKey()])
            ->fillForm(['password' => 'admin-set-password'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('admin-set-password', $employee->refresh()->password));
    }
}
