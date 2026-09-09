<?php

namespace Database\Factories;

use App\Models\Procedure;
use App\Models\Professional;
use App\Models\ProfessionalProcedureCommission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProfessionalProcedureCommission>
 */
class ProfessionalProcedureCommissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'professional_id' => Professional::factory(),
            'procedure_id' => Procedure::factory(),
            'commission_type' => 'PERCENT',
            'commission_value' => fake()->randomElement(['30.00', '40.00', '50.00']),
        ];
    }
}
