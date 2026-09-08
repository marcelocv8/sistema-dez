<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('agreement_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('RASCUNHO');
            $table->decimal('subtotal_gross', 12, 2)->default(0);
            $table->string('prescription_disk', 50)->default('local');
            $table->string('prescription_path')->nullable();
            $table->string('prescription_original_name', 255)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['status', 'sent_at']);
            $table->index('patient_id');
        });

        Schema::create('budget_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('procedure_id')->constrained()->restrictOnDelete();
            $table->string('procedure_name');
            $table->decimal('amount', 12, 2);
            $table->unsignedInteger('quantity')->default(1);
            $table->timestampsTz();
            $table->index('budget_id');
        });

        Schema::create('routine_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type', 30);
            $table->string('ref_type', 60)->nullable();
            $table->string('ref_id', 64)->nullable();
            $table->string('title', 200);
            $table->text('detail')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date');
            $table->string('status', 20)->default('PENDENTE');
            $table->text('note')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('done_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['type', 'ref_id', 'due_date']);
            $table->index(['status', 'due_date']);
            $table->index('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routine_tasks');
        Schema::dropIfExists('budget_items');
        Schema::dropIfExists('budgets');
    }
};
