<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\PatientAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientAlert>
 */
class PatientAlertFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $alerts = [
            ['kind' => PatientAlert::KIND_ALERGIA, 'title' => 'Alergia a dipirona', 'detail' => 'Urticária generalized em uso prévio.'],
            ['kind' => PatientAlert::KIND_ALERGIA, 'title' => 'Alergia a penicilina', 'detail' => 'Anafilaxia na infância.'],
            ['kind' => PatientAlert::KIND_CONDICAO, 'title' => 'Diabetes tipo 2', 'detail' => 'Em uso de metformina 850mg 2x/dia.'],
            ['kind' => PatientAlert::KIND_CONDICAO, 'title' => 'Hipertensão arterial', 'detail' => 'Losartana 50mg/dia.'],
            ['kind' => PatientAlert::KIND_OUTRO, 'title' => 'Uso de anticoagulante', 'detail' => 'Warfarina 5mg/dia - risco de sangramento.'],
        ];
        $alert = fake()->randomElement($alerts);

        return [
            'patient_id' => Patient::factory(),
            'kind' => $alert['kind'],
            'title' => $alert['title'],
            'detail' => $alert['detail'],
            'active' => true,
            'created_by' => User::factory(),
        ];
    }
}
