<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Maintenance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Maintenance>
 */
class MaintenanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'asset_id' => null,
            'title' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'location' => fake()->words(2, true),
            'scheduled_date' => fake()->date(),
            'priority' => Maintenance::PRIORITY_ROUTINE,
        ];
    }

    /**
     * Indicate that the maintenance is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'completed_at' => fake()->date(),
        ]);
    }
}
