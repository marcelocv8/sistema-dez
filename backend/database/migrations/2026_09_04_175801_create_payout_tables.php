<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professional_procedure_commissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('professional_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('procedure_id')->constrained()->cascadeOnDelete();
            $table->string('commission_type', 10);
            $table->decimal('commission_value', 10, 2);
            $table->timestampsTz();
            $table->unique(['professional_id', 'procedure_id']);
        });

        Schema::create('payouts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('professional_id')->constrained()->restrictOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->string('status', 20)->default('OPEN');
            $table->decimal('subtotal_gross', 12, 2)->default(0);
            $table->decimal('total_commission', 12, 2)->default(0);
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('confirmed_at')->nullable();
            $table->text('contest_note')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('paid_at')->nullable();
            $table->foreignUuid('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('cash_movement_id')->nullable()->constrained()->nullOnDelete();
            $table->string('proof_reference', 120)->nullable();
            $table->timestampsTz();
            $table->index(['professional_id', 'status']);
        });

        Schema::create('payout_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('payout_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('appointment_id')->constrained()->nullOnDelete();
            $table->decimal('gross_amount', 12, 2);
            $table->decimal('commission_amount', 12, 2);
            $table->string('rule', 60);
            $table->timestampsTz();
            $table->unique('appointment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_items');
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('professional_procedure_commissions');
    }
};
