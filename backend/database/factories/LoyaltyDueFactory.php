<?php

namespace Database\Factories;

use App\Models\LoyaltyDue;
use App\Models\LoyaltySubscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoyaltyDue>
 */
class LoyaltyDueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $today = CarbonImmutable::today((string) config('sistema_dez.timezone'));

        return [
            'subscription_id' => LoyaltySubscription::factory(),
            'competence' => $today->startOfMonth(),
            'amount' => fake()->randomFloat(2, 50, 200),
            'due_date' => $today->setDay(10),
            'status' => 'PENDENTE',
        ];
    }

    public function overdue(): static
    {
        return $this->state(fn () => [
            'competence' => CarbonImmutable::today((string) config('sistema_dez.timezone'))->subMonth()->startOfMonth(),
            'due_date' => CarbonImmutable::today((string) config('sistema_dez.timezone'))->subDays(5),
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'competence' => CarbonImmutable::today((string) config('sistema_dez.timezone'))->subMonths(2)->startOfMonth(),
            'due_date' => CarbonImmutable::today((string) config('sistema_dez.timezone'))->subMonths(2)->setDay(10),
            'status' => 'PAGA',
            'paid_at' => now(),
        ]);
    }
}
