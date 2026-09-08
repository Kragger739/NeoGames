<?php

namespace Database\Factories;

use App\Models\IconicArtist;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IconicArtist>
 */
class IconicArtistFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->name(),
            'image_path' => null,
            'enabled' => true,
            'sort_order' => 0,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }
}
