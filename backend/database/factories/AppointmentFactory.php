<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\ServiceOffering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
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
            'service_offering_id' => ServiceOffering::factory(),
            'scheduled_at' => now()->addDay(),
            'recommended_arrival_at' => now()->addDay(),
            'status' => 'CONFIRMED',
            'kind' => 'NEW',
            'origin' => 'ONLINE',
            'price_snapshot' => 100,
            'idempotency_key' => fake()->uuid(),
        ];
    }
}
