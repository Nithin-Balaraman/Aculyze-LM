<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Profile picture upload: avatars are stored on the 'avatars' disk (public/
 * avatars directly, not storage/app/public — Hostinger deploys have no SSH
 * access to run storage:link). Two independent ways to set one: any user via
 * the self-service profile page, or Admin on behalf of anyone via the
 * Employees (UserResource) screen.
 */
class AvatarUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('avatars');
    }

    public function test_user_without_an_avatar_falls_back_to_null_from_the_filament_avatar_url(): void
    {
        $user = User::factory()->create(['avatar' => null]);

        $this->assertNull($user->getFilamentAvatarUrl());
    }

    public function test_avatar_url_resolves_against_the_avatars_disk_once_set(): void
    {
        $user = User::factory()->create(['avatar' => 'avatars/example.jpg']);

        $this->assertSame(
            Storage::disk('avatars')->url('avatars/example.jpg'),
            $user->getFilamentAvatarUrl()
        );
    }

    public function test_employee_can_upload_their_own_avatar_via_the_profile_page(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        $file = UploadedFile::fake()->image('avatar.jpg');

        Livewire::test(EditProfile::class)
            ->fillForm(['avatar' => $file])
            ->call('save')
            ->assertHasNoFormErrors();

        $employee->refresh();
        $this->assertNotNull($employee->avatar);
        Storage::disk('avatars')->assertExists($employee->avatar);
    }

    public function test_admin_can_set_another_employees_avatar_from_the_employees_screen(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create();
        $this->actingAs($admin);

        $file = UploadedFile::fake()->image('avatar.jpg');

        Livewire::test(EditUser::class, ['record' => $employee->getRouteKey()])
            ->fillForm(['avatar' => $file])
            ->call('save')
            ->assertHasNoFormErrors();

        $employee->refresh();
        $this->assertNotNull($employee->avatar);
        Storage::disk('avatars')->assertExists($employee->avatar);
    }
}
