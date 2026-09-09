<?php

namespace Database\Factories;

use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatConversation>
 */
class ChatConversationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'DIRECT',
            'title' => null,
            'unit_id' => null,
            'created_by' => User::factory(),
        ];
    }

    public function group(): static
    {
        return $this->state(fn () => ['type' => 'GROUP', 'title' => fake()->words(2, true)]);
    }
}
