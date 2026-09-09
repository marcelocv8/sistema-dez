<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('loyalty_dues')->whereNotNull('cash_movement_id')->orderBy('id')->each(function ($due): void {
            DB::table('cash_movements')->where('id', $due->cash_movement_id)->where('type', 'MENSALIDADE')
                ->whereNull('loyalty_due_id')->update(['loyalty_due_id' => $due->id]);
        });
    }

    public function down(): void
    {
        // The repaired references remain valid with the previous schema.
    }
};
