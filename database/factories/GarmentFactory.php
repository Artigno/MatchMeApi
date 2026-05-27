<?php

namespace Database\Factories;

use App\Models\Garment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Garment>
 */
class GarmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category' => fake()->randomElement(['top', 'bottom', 'shoes', 'accessory', 'outerwear']),
            'brand' => fake()->company(),
            'color' => fake()->colorName(),
            'condition' => fake()->randomElement(['new', 'like_new', 'good', 'fair', 'poor']),
            'description' => fake()->sentence(),
        ];
    }
}
