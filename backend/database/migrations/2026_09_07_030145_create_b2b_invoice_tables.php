<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('b2b_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agreement_id')->constrained()->restrictOnDelete();
            $table->date('competence');
            $table->string('status', 20)->default('EMITIDA');
            $table->decimal('subtotal_gross', 12, 2)->default(0);
            $table->decimal('settled_amount', 12, 2)->default(0);
            $table->timestampTz('issued_at');
            $table->date('due_at');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('settled_at')->nullable();
            $table->string('proof_reference', 255)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['agreement_id', 'competence']);
            $table->index(['status', 'due_at']);
        });

        Schema::create('b2b_invoice_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('b2b_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('appointment_id')->unique()->constrained()->restrictOnDelete();
            $table->timestampTz('scheduled_at');
            $table->string('patient_name');
            $table->string('procedure_name');
            $table->decimal('gross_amount', 12, 2);
            $table->timestampsTz();
            $table->index('b2b_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_invoice_items');
        Schema::dropIfExists('b2b_invoices');
    }
};
