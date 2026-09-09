<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\B2bInvoice;
use App\Models\B2bInvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<B2bInvoiceItem>
 */
class B2bInvoiceItemFactory extends Factory
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
            'b2b_invoice_id' => B2bInvoice::factory(),
            'appointment_id' => $appointment->id,
            'scheduled_at' => $appointment->scheduled_at,
            'patient_name' => $appointment->patient?->name ?? fake()->name(),
            'procedure_name' => $appointment->serviceOffering->procedure->name,
            'gross_amount' => $appointment->price_snapshot,
        ];
    }
}
