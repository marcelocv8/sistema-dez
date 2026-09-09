<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Payout;
use App\Models\PayoutItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayoutItem>
 */
class PayoutItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $gross = fake()->randomFloat(2, 80, 900);

        return [
            'payout_id' => Payout::factory(),
            'appointment_id' => Appointment::factory(),
            'gross_amount' => $gross,
            'commission_amount' => round($gross * 0.4, 2),
            'rule' => 'PERCENT 40%',
        ];
    }
}
