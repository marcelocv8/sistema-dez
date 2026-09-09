<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\ProntuarioEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProntuarioEvent>
 */
class ProntuarioEventFactory extends Factory
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
            'appointment_id' => null,
            'subject_id' => null,
            'event' => 'CRIADO',
            'by_user_id' => User::factory(),
            'payload' => null,
        ];
    }
}
