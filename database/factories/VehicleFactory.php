<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
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
            'name' => fake()->words(2, true),
            'brand' => fake()->company(),
            'model' => fake()->words(2, true),
            'year' => fake()->numberBetween(2000, 2026),
            'plate_number' => strtoupper(fake()->bothify('? #### ???')),
        ];
    }
}
