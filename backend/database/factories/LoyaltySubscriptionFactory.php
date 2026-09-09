<?php

namespace Database\Factories;

use App\Models\LoyaltyPlan;
use App\Models\LoyaltySubscription;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoyaltySubscription>
 */
class LoyaltySubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_id' => LoyaltyPlan::factory(),
            'patient_id' => Patient::factory(),
            'status' => 'ACTIVE',
            'started_at' => CarbonImmutable::today((string) config('sistema_dez.timezone')),
            'notes' => null,
            'created_by' => User::factory(),
        ];
    }
}
