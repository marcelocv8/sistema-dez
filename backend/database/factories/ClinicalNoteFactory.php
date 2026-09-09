<?php

namespace Database\Factories;

use App\Models\ClinicalNote;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicalNote>
 */
class ClinicalNoteFactory extends Factory
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
            'professional_id' => null,
            'kind' => ClinicalNote::KIND_EVOLUCAO,
            'content_html' => '<p>'.fake()->randomElement([
                'Paciente refere melhora dos sintomas após medicação prescrita.',
                'Queixa de dor lombar há 3 dias, sem irradiação. Sem sinais de alarme.',
                'Seguimento clínico: pressão arterial controlada, sem intercorrências.',
                'Alergia a dipirona referida pelo paciente na anamnese.',
            ]).'</p>',
            'created_by' => User::factory(),
            'retention_until' => now()->addYears(20),
        ];
    }

    public function anamnese(): static
    {
        return $this->state(fn () => ['kind' => ClinicalNote::KIND_ANAMNESE]);
    }
}
