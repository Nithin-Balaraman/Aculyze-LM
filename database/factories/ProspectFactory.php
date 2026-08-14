<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Prospect>
 */
class ProspectFactory extends Factory
{
    public function definition(): array
    {
        $owner = User::factory()->create();

        return [
            'company_name' => fake()->company(),
            'contact_person' => fake()->name(),
            'telephone' => fake()->numerify('+91 9#### #####'),
            'email' => fake()->companyEmail(),
            'industry' => fake()->randomElement(['Textiles', 'Precision Engineering', 'Industrial Automation']),
            'city' => 'Coimbatore',
            'assigned_to' => $owner,
            'created_by' => $owner,
        ];
    }
}
