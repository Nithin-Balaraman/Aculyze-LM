<?php

namespace Database\Factories;

use App\Enums\DemoMode;
use App\Enums\DemoStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Mirrors ProspectFactory's convention: organization_id is deliberately
 * NOT set here (inherited from the given lead_id via
 * Demo::inheritedOrganizationId() — see App\Models\Concerns\
 * BelongsToOrganization), so this factory must be used within an active
 * App\Support\Tenancy\Tenancy context or with an explicit lead_id
 * belonging to the current tenant. No LeadFactory exists in this codebase
 * (Leads are created directly with explicit fields in every existing
 * test) — callers must supply 'lead_id'/'prospect_id'/'assigned_to'/
 * 'created_by' explicitly.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Demo>
 */
class DemoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'demo_at' => now()->addDays(2),
            'mode' => DemoMode::OnSite,
            'location' => fake()->streetAddress(),
            'meeting_link' => null,
            'attendees' => [
                ['name' => fake()->name(), 'designation' => 'Manager', 'organization' => fake()->company()],
            ],
            'product_service' => fake()->word(),
            'purpose' => fake()->sentence(),
            'status' => DemoStatus::Scheduled,
        ];
    }

    public function online(): self
    {
        return $this->state(fn () => [
            'mode' => DemoMode::Online,
            'location' => null,
            'meeting_link' => fake()->url(),
        ]);
    }
}
