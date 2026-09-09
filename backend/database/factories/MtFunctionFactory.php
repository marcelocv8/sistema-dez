<?php

namespace Database\Factories;

use App\Models\Agreement;
use App\Models\MtFunction;
use App\Models\MtRisk;
use App\Models\MtSector;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MtFunction>
 */
class MtFunctionFactory extends Factory
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
            'sector_id' => MtSector::factory(),
            'risk_id' => MtRisk::factory(),
            'name' => fake()->unique()->word(),
            'active' => true,
            'legacy_id' => fake()->unique()->numberBetween(1, 9999),
        ];
    }
}
