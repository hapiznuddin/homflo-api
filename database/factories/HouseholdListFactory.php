<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\HouseholdList;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HouseholdList>
 */
class HouseholdListFactory extends Factory
{
    protected $model = HouseholdList::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'name' => fake()->words(2, true),
            'type' => 'shopping',
            'created_by' => User::factory(),
            'archived_at' => null,
        ];
    }

    /** Attach the list to an existing household. */
    public function forHousehold(Household|string $household): static
    {
        return $this->state(fn () => [
            'household_id' => $household instanceof Household ? $household->id : $household,
        ]);
    }

    /** Create a custom (non-shopping) list. */
    public function custom(): static
    {
        return $this->state(fn () => ['type' => 'custom']);
    }

    /** Create an archived list. */
    public function archived(): static
    {
        return $this->state(fn () => ['archived_at' => now()]);
    }
}
