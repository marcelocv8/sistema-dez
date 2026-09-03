<?php

namespace Database\Factories;

use App\Enums\PriceStatus;
use App\Models\Agreement;
use App\Models\Procedure;
use App\Models\ProcedurePrice;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProcedurePrice> */
class ProcedurePriceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'procedure_id' => Procedure::factory(),
            'agreement_id' => Agreement::factory(),
            'unit_id' => Unit::factory(),
            'nature' => 'PRIVATE',
            'amount' => fake()->randomFloat(2, 30, 500),
            'status' => PriceStatus::Valid,
            'source_file' => 'test.csv',
            'source_key' => hash('sha256', fake()->uuid()),
            'source_checksum' => hash('sha256', fake()->uuid()),
        ];
    }
}
