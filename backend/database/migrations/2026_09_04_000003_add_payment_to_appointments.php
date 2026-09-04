<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->string('payment_status', 20)->default('PENDING')->after('price_snapshot');
            $table->timestampTz('paid_at')->nullable()->after('payment_status');
            $table->foreignUuid('payment_method_id')->nullable()->after('paid_at')->constrained()->nullOnDelete();
            $table->foreignUuid('cash_movement_id')->nullable()->after('payment_method_id')->constrained('cash_movements')->nullOnDelete();
            $table->string('b2b_authorization_code', 60)->nullable()->after('cash_movement_id');
            $table->timestampTz('care_started_at')->nullable()->after('b2b_authorization_code');
            $table->index('payment_status');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropIndex(['payment_status']);
            $table->dropConstrainedForeignId('cash_movement_id');
            $table->dropConstrainedForeignId('payment_method_id');
            $table->dropColumn(['care_started_at', 'b2b_authorization_code', 'paid_at', 'payment_status']);
        });
    }
};
