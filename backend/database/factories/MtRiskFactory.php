<?php

namespace Database\Factories;

use App\Models\MtRisk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MtRisk>
 */
class MtRiskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'active' => true,
            'legacy_id' => fake()->unique()->numberBetween(1, 9999),
        ];
    }
}
