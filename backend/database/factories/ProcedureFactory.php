<?php

namespace Database\Factories;

use App\Enums\ProcedureType;
use App\Models\Procedure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Procedure>
 */
class ProcedureFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'type' => ProcedureType::Consultation,
            'duration_minutes' => 30,
            'active' => true,
            'legacy' => false,
            'online_enabled' => true,
        ];
    }
}
