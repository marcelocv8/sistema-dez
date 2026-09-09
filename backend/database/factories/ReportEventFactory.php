<?php

namespace Database\Factories;

use App\Models\Report;
use App\Models\ReportEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportEvent>
 */
class ReportEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'report_id' => Report::factory(),
            'event' => ReportEvent::CREATED,
            'by_user_id' => User::factory(),
            'payload' => null,
        ];
    }
}
