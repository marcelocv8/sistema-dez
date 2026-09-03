<?php

namespace Database\Factories;

use App\Enums\BookingMode;
use App\Enums\Modality;
use App\Models\Agreement;
use App\Models\CatalogLink;
use App\Models\Procedure;
use App\Models\ProcedurePrice;
use App\Models\Professional;
use App\Models\ServiceOffering;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceOffering>
 */
class ServiceOfferingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'unit_id' => Unit::factory(),
            'procedure_id' => Procedure::factory(),
            'professional_id' => Professional::factory(),
            'agreement_id' => Agreement::factory(),
            'booking_mode' => BookingMode::FixedSlot,
            'modality' => Modality::Presential,
            'price' => fake()->randomFloat(2, 80, 500),
            'arrival_block_minutes' => 60,
            'arrival_block_capacity' => 3,
            'online_enabled' => true,
            'active' => true,
        ];
    }

    public function arrivalBlock(): static
    {
        return $this->state(fn () => ['booking_mode' => BookingMode::ArrivalBlock]);
    }

    public function published(): static
    {
        return $this->afterCreating(function (ServiceOffering $offering): void {
            $offering->agreement->update(['is_default' => true, 'requires_authorization' => false]);
            $link = CatalogLink::create(['unit_id' => $offering->unit_id, 'procedure_id' => $offering->procedure_id,
                'professional_id' => $offering->professional_id, 'agreement_id' => $offering->agreement_id, 'active' => true]);
            $price = ProcedurePrice::factory()->create(['procedure_id' => $offering->procedure_id, 'unit_id' => $offering->unit_id,
                'agreement_id' => $offering->agreement_id, 'amount' => $offering->price]);
            $offering->forceFill(['catalog_link_id' => $link->id, 'procedure_price_id' => $price->id,
                'online_enabled' => true, 'online_paused' => false, 'published_at' => now(), 'published_until' => null])->save();
        });
    }

    public function walkInInfo(): static
    {
        return $this->state(fn () => ['booking_mode' => BookingMode::WalkInInfo]);
    }
}
