<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletAdjustment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WalletAdjustment>
 */
class WalletAdjustmentFactory extends Factory
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
            'wallet_id' => Wallet::factory(),
            'amount' => fake()->randomFloat(2, 10000, 500000),
            'balance_before' => 0,
            'balance_after' => 0,
            'reason' => fake()->sentence(),
            'created_by' => User::factory(),
        ];
    }
}
