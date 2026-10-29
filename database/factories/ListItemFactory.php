<?php

namespace Database\Factories;

use App\Models\HouseholdList;
use App\Models\ListItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ListItem>
 */
class ListItemFactory extends Factory
{
    protected $model = ListItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'list_id' => HouseholdList::factory(),
            'title' => fake()->words(3, true),
            'quantity' => null,
            'unit' => null,
            'estimated_price' => null,
            'actual_price' => null,
            'completed_at' => null,
            'completed_by' => null,
            'created_by' => User::factory(),
        ];
    }

    /** Attach the item to an existing list. */
    public function forList(HouseholdList|string $list): static
    {
        return $this->state(fn () => [
            'list_id' => $list instanceof HouseholdList ? $list->id : $list,
        ]);
    }

    /** Mark the item completed. */
    public function completed(): static
    {
        return $this->state(fn () => ['completed_at' => now()]);
    }
}
