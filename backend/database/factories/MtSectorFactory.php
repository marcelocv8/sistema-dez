<?php

namespace Database\Factories;

use App\Models\Agreement;
use App\Models\MtSector;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MtSector>
 */
class MtSectorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agreement_id' => Agreement::factory(),
            'name' => fake()->unique()->word(),
            'active' => true,
            'legacy_id' => fake()->unique()->numberBetween(1, 9999),
        ];
    }
}
