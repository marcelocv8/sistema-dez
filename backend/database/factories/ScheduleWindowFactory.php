<?php

namespace Database\Factories;

use App\Models\ScheduleWindow;
use App\Models\ServiceOffering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleWindow>
 */
class ScheduleWindowFactory extends Factory
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
            'effective_from' => now()->startOfYear(),
            'effective_to' => null,
            'weekday' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '12:00:00',
            'slot_minutes' => 30,
            'active' => true,
        ];
    }
}
