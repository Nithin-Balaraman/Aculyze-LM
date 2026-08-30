<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::Employee,
            // A User has no parent record to inherit organization_id from,
            // and is typically created before any acting-as/TenantContext
            // exists (you must create a user before you can authenticate as
            // them). Reusing whichever Organization already exists in the
            // current (test) database — rather than creating a new one on
            // every single factory call — gives every test a single,
            // consistent implicit organization by default, matching how the
            // pre-Phase-1 test suite already implicitly assumed one shared
            // company; a test that explicitly wants a second organization
            // for isolation testing creates one and overrides this.
            'organization_id' => fn () => Organization::query()->value('id') ?? Organization::factory()->create()->id,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Admin,
        ]);
    }
}
