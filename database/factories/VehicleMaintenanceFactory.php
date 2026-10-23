<?php

namespace Database\Factories;

use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleMaintenance>
 */
class VehicleMaintenanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'title' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'scheduled_date' => fake()->date(),
        ];
    }

    /**
     * Indicate that the vehicle service is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'completed_at' => fake()->date(),
        ]);
    }
}
