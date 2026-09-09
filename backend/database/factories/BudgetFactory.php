<?php

namespace Database\Factories;

use App\Models\Agreement;
use App\Models\Budget;
use App\Models\Patient;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Budget>
 */
class BudgetFactory extends Factory
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
            'unit_id' => Unit::factory(),
            'agreement_id' => Agreement::factory(),
            'status' => 'RASCUNHO',
            'subtotal_gross' => fake()->randomFloat(2, 80, 1200),
            'created_by' => User::factory(),
        ];
    }

    public function sent(): static
    {
        return $this->state(fn () => ['status' => 'ENVIADO', 'sent_at' => now()->subDays(fake()->numberBetween(1, 15))]);
    }

    public function accepted(): static
    {
        return $this->state(fn () => ['status' => 'ACEITO', 'sent_at' => now()->subDays(10), 'resolved_at' => now()->subDays(5)]);
    }
}
