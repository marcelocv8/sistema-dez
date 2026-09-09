<?php

namespace Database\Factories;

use App\Models\Agreement;
use App\Models\LoyaltyPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoyaltyPlan>
 */
class LoyaltyPlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'SOU DEZ '.fake()->unique()->word(),
            'agreement_id' => Agreement::factory(),
            'monthly_fee' => fake()->randomFloat(2, 50, 200),
            'due_day' => fake()->numberBetween(1, 28),
            'active' => true,
        ];
    }
}
