<?php

namespace Database\Factories;

use App\Models\AgentToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentToken>
 */
class AgentTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'token_hash' => hash('sha256', fake()->unique()->sha256()),
            'scopes' => ['agenda.read'],
            'active' => true,
            'created_by' => User::factory(),
        ];
    }

    public function withScopes(array $scopes): static
    {
        return $this->state(fn (): array => ['scopes' => $scopes]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['active' => false, 'revoked_at' => now()]);
    }
}
