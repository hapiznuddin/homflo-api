<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Household;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
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
            'type' => Transaction::TYPE_EXPENSE,
            'amount' => fake()->randomFloat(2, 10000, 5000000),
            'occurred_on' => fake()->date(),
            'description' => fake()->sentence(),
            'wallet' => 'Kas Tunai',
        ];
    }

    /**
     * Indicate that the transaction is income.
     */
    public function income(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Transaction::TYPE_INCOME,
        ]);
    }

    /**
     * Indicate that the transaction is an expense.
     */
    public function expense(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Transaction::TYPE_EXPENSE,
        ]);
    }
}
