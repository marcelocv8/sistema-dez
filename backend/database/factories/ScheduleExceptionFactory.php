<?php

namespace Database\Factories;

use App\Models\ScheduleException;
use App\Models\ServiceOffering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleException>
 */
class ScheduleExceptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_offering_id' => ServiceOffering::factory(),
            'date' => now()->toDateString(),
            'starts_at' => null,
            'ends_at' => null,
            'reason' => 'Indisponibilidade',
            'blocked' => true,
        ];
    }
}
