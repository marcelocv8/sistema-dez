<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashMovement>
 */
class CashMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $gross = fake()->randomFloat(2, 60, 800);

        return [
            'cash_register_id' => CashRegister::factory(),
            'type' => 'RECEBIMENTO',
            'payment_method_id' => PaymentMethod::query()->inRandomOrder()->value('id') ?? PaymentMethod::factory(),
            'gross_amount' => $gross,
            'fee_amount' => '0.00',
            'net_amount' => $gross,
            'appointment_id' => Appointment::factory(),
            'receipt_number' => 1,
            'description' => fake()->optional()->sentence(3),
            'created_by' => User::factory(),
        ];
    }
}
