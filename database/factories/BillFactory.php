<?php

namespace Database\Factories;

use App\Models\Bill;
use App\Models\Category;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bill>
 */
class BillFactory extends Factory
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
            'category_id' => Category::factory(),
            'title' => fake()->words(2, true),
            'amount' => fake()->randomFloat(2, 50000, 2000000),
            'due_date' => fake()->date(),
            'is_active' => true,
        ];
    }
}
