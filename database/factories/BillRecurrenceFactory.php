<?php

namespace Database\Factories;

use App\Models\BillRecurrence;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillRecurrence>
 */
class BillRecurrenceFactory extends Factory
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
            'amount' => fake()->randomFloat(2, 50000, 2000000),
            'frequency' => BillRecurrence::FREQUENCY_MONTHLY,
            'due_day' => fake()->numberBetween(1, 28),
            'is_active' => true,
            'materialized_until' => null,
        ];
    }
}
