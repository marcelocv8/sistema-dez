<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\PortalAccess;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<PortalAccess>
 */
class PortalAccessFactory extends Factory
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
            'login' => fake()->unique()->numerify('MOCK######'),
            'password_hash' => Hash::make(Str::random(12)),
            'token_hash' => hash('sha256', Str::random(40)),
            'expires_at' => now()->addDays(7),
            'access_count' => 0,
            'created_by' => User::factory(),
        ];
    }
}
