<?php

namespace Database\Factories;

use App\Models\Payout;
use App\Models\Professional;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payout>
 */
class PayoutFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $from = fake()->dateTimeBetween('-2 months', '-1 month');

        return [
            'professional_id' => Professional::factory(),
            'period_from' => $from->format('Y-m-01'),
            'period_to' => $from->format('Y-m-t'),
            'status' => 'OPEN',
            'subtotal_gross' => '0.00',
            'total_commission' => '0.00',
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => 'PAID', 'confirmed_at' => now()->subDays(8), 'paid_at' => now()->subDays(3)]);
    }
}
