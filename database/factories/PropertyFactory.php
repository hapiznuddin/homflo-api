<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
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
            'type' => 'house',
            'address' => fake()->address(),
            'description' => fake()->sentence(),
            'ownership_status' => 'owned',
            'notes' => null,
            'archived_at' => null,
        ];
    }

    /**
     * Indicate that the property is archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'archived_at' => now(),
        ]);
    }
}
