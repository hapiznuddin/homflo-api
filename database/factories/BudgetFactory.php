<?php

namespace Database\Factories;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Budget>
 */
class BudgetFactory extends Factory
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
            'year' => (int) now()->format('Y'),
            'month' => (int) now()->format('n'),
            'amount' => fake()->randomFloat(2, 500000, 10000000),
        ];
    }
}
