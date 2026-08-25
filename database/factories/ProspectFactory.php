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
            'designation' => fake()->randomElement(['Manager', 'Owner', 'Director', 'Procurement Head']),
            'telephone' => fake()->numerify('+91 9#### #####'),
            'email' => fake()->companyEmail(),
            'website' => fake()->url(),
            'industry' => fake()->randomElement(['Textiles', 'Precision Engineering', 'Industrial Automation']),
            'source' => fake()->randomElement(['Referral', 'Trade Directory', 'Cold Outreach', 'Exhibition']),
            'address' => fake()->streetAddress(),
            'locality' => fake()->citySuffix(),
            'city' => 'Coimbatore',
            'state' => 'Tamil Nadu',
            'pincode' => fake()->numerify('######'),
            'notes' => fake()->sentence(),
            'assigned_to' => $owner,
            'created_by' => $owner,
        ];
    }
}
