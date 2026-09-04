<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_registers', function (Blueprint $table): void {
            $table->decimal('counted_cash', 12, 2)->nullable()->after('float_amount');
            $table->decimal('cash_difference', 12, 2)->nullable()->after('counted_cash');
            $table->text('close_note')->nullable()->after('cash_difference');
            $table->foreignId('shortage_approved_by')->nullable()->after('closed_by')->constrained('users')->nullOnDelete();
        });

        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->string('proof_reference', 120)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->dropColumn('proof_reference');
        });

        Schema::table('cash_registers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('shortage_approved_by');
            $table->dropColumn(['counted_cash', 'cash_difference', 'close_note']);
        });
    }
};
