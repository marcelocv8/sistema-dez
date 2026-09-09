<?php

namespace Database\Factories;

use App\Models\AttendanceEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceEvent>
 */
class AttendanceEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $day = fake()->dateTimeBetween('-20 days', 'now');

        return [
            'user_id' => User::factory(),
            'service_date' => $day->format('Y-m-d'),
            'logged_in_at' => $day->setTime(7, fake()->numberBetween(30, 59)),
            'created_at' => $day,
        ];
    }
}
