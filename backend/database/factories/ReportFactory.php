<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Professional;
use App\Models\Report;
use App\Models\ReportTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $appointment = Appointment::factory()->create();

        return [
            'appointment_id' => $appointment->id,
            'patient_id' => $appointment->patient_id,
            'procedure_id' => $appointment->serviceOffering->procedure_id,
            'doctor_id' => Professional::factory(),
            'template_id' => ReportTemplate::factory(),
            'content_html' => '<p>'.$this->clinicalText().'</p>',
            'status' => Report::STATUS_RASCUNHO,
            'typed_by' => User::factory(),
            'retention_until' => now()->addYears(20),
        ];
    }

    public function finalized(): static
    {
        return $this->state(fn () => ['status' => Report::STATUS_FINALIZADO, 'finalized_at' => now()->subDays(fake()->numberBetween(1, 10))]);
    }

    private function clinicalText(): string
    {
        return fake()->randomElement([
            'Exame dentro dos padrões de normalidade. Não são identificadas alterações estruturais.',
            'Sugestão de acompanhamento clínico ambulatorial. Achados compatíveis com quadro benigno.',
            'Pequena alteração inicial sem relevância clínica. Recomenda-se controle em 6 meses.',
            'Estudo dinâmico sem alterações significativas. Hipótese diagnóstica mantida.',
        ]);
    }
}
