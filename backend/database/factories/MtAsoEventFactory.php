<?php

namespace Database\Factories;

use App\Models\MtAso;
use App\Models\MtAsoEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MtAsoEvent>
 */
class MtAsoEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mt_aso_id' => MtAso::factory(),
            'event' => MtAsoEvent::CREATED,
            'by_user_id' => User::factory(),
            'payload' => null,
        ];
    }
}
