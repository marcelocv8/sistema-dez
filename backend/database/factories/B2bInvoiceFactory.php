<?php

namespace Database\Factories;

use App\Models\Agreement;
use App\Models\B2bInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<B2bInvoice>
 */
class B2bInvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $competence = fake()->dateTimeBetween('-3 months', '-1 month');

        return [
            'agreement_id' => Agreement::factory(),
            'competence' => $competence->format('Y-m-01'),
            'status' => 'EMITIDA',
            'subtotal_gross' => '0.00',
            'settled_amount' => '0.00',
            'issued_at' => now(),
            'due_at' => now()->addDays(15),
            'created_by' => User::factory(),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => 'PAGA', 'settled_at' => now()->subDays(5), 'settled_amount' => fake()->randomFloat(2, 500, 5000)]);
    }
}
