<?php

namespace Database\Factories;

use App\Models\LoyaltyDependent;
use App\Models\LoyaltySubscription;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoyaltyDependent>
 */
class LoyaltyDependentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => LoyaltySubscription::factory(),
            'patient_id' => Patient::factory(),
            'active' => true,
        ];
    }
}
