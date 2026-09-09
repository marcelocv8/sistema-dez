<?php

namespace Database\Factories;

use App\Models\Budget;
use App\Models\BudgetItem;
use App\Models\ProcedurePrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BudgetItem>
 */
class BudgetItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $price = ProcedurePrice::factory()->create();

        return [
            'budget_id' => Budget::factory(),
            'procedure_id' => $price->procedure_id,
            'procedure_price_id' => $price->id,
            'procedure_name' => $price->procedure->name,
            'amount' => $price->amount,
            'quantity' => 1,
        ];
    }
}
