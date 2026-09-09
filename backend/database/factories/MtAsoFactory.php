<?php

namespace Database\Factories;

use App\Enums\AsoStatus;
use App\Enums\AsoType;
use App\Models\Agreement;
use App\Models\MtAso;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MtAso>
 */
class MtAsoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'agreement_id' => Agreement::factory(),
            'sector_id' => null,
            'function_id' => null,
            'type' => AsoType::Periodico,
            'status' => AsoStatus::Pendente,
            'realized_at' => null,
            'expires_at' => now()->addDays(rand(30, 300))->toDateString(),
            'notes' => null,
            'legacy_id' => fake()->unique()->numberBetween(1, 999999),
            'created_by' => User::factory(),
        ];
    }

    public function emitted(): static
    {
        return $this->state(fn (): array => [
            'status' => AsoStatus::Emitido,
            'realized_at' => fake()->dateTimeBetween('-11 months', '-1 month')->format('Y-m-d'),
            'expires_at' => fake()->dateTimeBetween('+1 day', '+1 month')->format('Y-m-d'),
            'issued_at' => now(),
        ]);
    }
}
