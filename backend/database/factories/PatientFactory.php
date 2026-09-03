<?php

namespace Database\Factories;

use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'birth_date' => fake()->dateTimeBetween('-90 years', '-1 year'),
            'phone' => fake()->numerify('859########'),
            'email' => fake()->unique()->safeEmail(),
        ];
    }
}
