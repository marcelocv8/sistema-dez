<?php

namespace Database\Factories;

use App\Models\RoutineTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoutineTask>
 */
class RoutineTaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => RoutineTask::MANUAL,
            'ref_type' => null,
            'ref_id' => null,
            'title' => fake()->sentence(4),
            'detail' => null,
            'assigned_to' => User::factory(),
            'due_date' => fake()->dateTimeBetween('-3 days', '+5 days')->format('Y-m-d'),
            'status' => 'PENDENTE',
            'note' => null,
            'created_by' => User::factory(),
        ];
    }
}
