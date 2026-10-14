<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
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
            'description' => fake()->sentence(),
            'location' => fake()->words(2, true),
            'acquired_on' => fake()->date(),
            'acquisition_cost' => fake()->randomFloat(2, 100000, 50000000),
        ];
    }
}
