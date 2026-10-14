<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\File;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<File>
 */
class FileFactory extends Factory
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
            'fileable_type' => Asset::class,
            'fileable_id' => Asset::factory(),
            'disk' => 'local',
            'path' => 'attachments/'.fake()->uuid().'.jpg',
            'original_name' => fake()->words(2, true).'.jpg',
            'mime_type' => 'image/jpeg',
            'size' => fake()->numberBetween(1024, 5242880),
        ];
    }
}
