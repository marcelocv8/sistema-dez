<?php

namespace Database\Factories;

use App\Models\ReportTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportTemplate>
 */
class ReportTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'legacy_type' => 'LAUDO',
            'legacy_id' => fake()->unique()->numberBetween(1000, 9999),
            'legacy_medico' => 'ADMINISTRADOR',
            'legacy_procedure' => null,
            'nome' => fake()->unique()->word(),
            'content_html' => '<p>Modelo de teste.</p>',
            'active' => true,
            'imported_at' => now(),
        ];
    }
}
