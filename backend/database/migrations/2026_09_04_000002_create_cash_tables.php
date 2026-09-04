<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->decimal('extra_percent', 5, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('cash_registers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('unit_id')->constrained()->restrictOnDelete();
            $table->string('shift', 20);
            $table->decimal('float_amount', 12, 2)->default(0);
            $table->string('status', 20)->default('ABERTO');
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
            $table->unsignedInteger('last_receipt_number')->default(0);
            $table->json('totals_snapshot')->nullable();
            $table->timestampsTz();
            $table->index(['unit_id', 'status']);
        });

        Schema::create('cash_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('cash_register_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->foreignUuid('payment_method_id')->constrained()->restrictOnDelete();
            $table->decimal('gross_amount', 12, 2);
            $table->decimal('fee_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2);
            $table->foreignUuid('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('receipt_number');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['cash_register_id', 'receipt_number']);
            $table->index('appointment_id');
        });

        DB::table('payment_methods')->insert([
            ['id' => (string) Str::uuid(), 'code' => 'ESPECIE', 'name' => 'Espécie', 'extra_percent' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::uuid(), 'code' => 'DEBITO', 'name' => 'Débito', 'extra_percent' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::uuid(), 'code' => 'CREDITO', 'name' => 'Crédito', 'extra_percent' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::uuid(), 'code' => 'PIX', 'name' => 'PIX', 'extra_percent' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_registers');
        Schema::dropIfExists('payment_methods');
    }
};
