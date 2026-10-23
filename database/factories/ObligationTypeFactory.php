<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\ObligationType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ObligationType>
 */
class ObligationTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The default is a household-owned custom type. The built-in catalogue is
     * seeded by migration and must not be created through this factory.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'code' => null,
            'name' => fake()->unique()->words(2, true),
            'is_system' => false,
        ];
    }

    /**
     * Create a system type for tests that need an additional fixed code.
     * Existing seeded types should normally be looked up by code instead.
     */
    public function system(string $code, string $name): static
    {
        return $this->state(fn (array $attributes) => [
            'household_id' => null,
            'code' => $code,
            'name' => $name,
            'is_system' => true,
        ]);
    }
}
