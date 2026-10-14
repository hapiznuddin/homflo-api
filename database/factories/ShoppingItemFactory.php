<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\ShoppingItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShoppingItem>
 */
class ShoppingItemFactory extends Factory
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
            'title' => fake()->words(2, true),
            'quantity' => fake()->randomFloat(2, 1, 10),
            'unit' => 'pcs',
            'estimated_price' => fake()->randomFloat(2, 10000, 500000),
            'is_purchased' => false,
        ];
    }
}
