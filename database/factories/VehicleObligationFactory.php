<?php

namespace Database\Factories;

use App\Models\ObligationType;
use App\Models\Vehicle;
use App\Models\VehicleObligation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleObligation>
 */
class VehicleObligationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The default uses a system type, which is valid for every household.
     * Tests that need a custom type should pass one explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'obligation_type_id' => fn (): string => ObligationType::query()
                ->where('code', ObligationType::CODE_TAX)
                ->value('id'),
            'title' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'issued_date' => null,
            'due_date' => fake()->date(),
            'amount' => null,
            'status' => VehicleObligation::STATUS_ACTIVE,
        ];
    }

    /**
     * Indicate that the administrative obligation is no longer applicable.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VehicleObligation::STATUS_INACTIVE,
        ]);
    }
}
