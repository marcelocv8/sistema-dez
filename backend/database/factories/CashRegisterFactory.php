<?php

namespace Database\Factories;

use App\Models\CashRegister;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashRegister>
 */
class CashRegisterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'unit_id' => Unit::factory(),
            'shift' => 'UNICO',
            'float_amount' => '100.00',
            'status' => 'ABERTO',
            'opened_by' => User::factory(),
            'opened_at' => now(),
            'last_receipt_number' => 0,
        ];
    }
}
