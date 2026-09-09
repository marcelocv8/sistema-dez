<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->foreignUuid('agreement_id')->constrained()->restrictOnDelete();
            $table->decimal('monthly_fee', 10, 2);
            $table->unsignedTinyInteger('due_day');
            $table->boolean('active')->default(true);
            $table->timestampsTz();
            $table->unique('agreement_id');
            $table->index('active');
        });

        Schema::create('loyalty_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('loyalty_plans')->restrictOnDelete();
            $table->foreignUuid('patient_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('ACTIVE');
            $table->date('started_at');
            $table->date('ended_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['patient_id', 'status']);
            $table->index('plan_id');
        });

        Schema::create('loyalty_dependents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('subscription_id')->constrained('loyalty_subscriptions')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained()->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
            $table->unique(['subscription_id', 'patient_id']);
            $table->index(['patient_id', 'active']);
        });

        Schema::create('loyalty_dues', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('subscription_id')->constrained('loyalty_subscriptions')->cascadeOnDelete();
            $table->date('competence');
            $table->decimal('amount', 10, 2);
            $table->date('due_date');
            $table->string('status', 20)->default('PENDENTE');
            $table->timestampTz('paid_at')->nullable();
            $table->foreignUuid('cash_movement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['subscription_id', 'competence']);
            $table->index(['status', 'due_date']);
        });

        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->foreignUuid('loyalty_due_id')->nullable()->constrained('loyalty_dues')->nullOnDelete()->after('appointment_id');
            $table->index('loyalty_due_id');
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->dropForeign(['loyalty_due_id']);
            $table->dropIndex(['loyalty_due_id']);
            $table->dropColumn('loyalty_due_id');
        });
        Schema::dropIfExists('loyalty_dues');
        Schema::dropIfExists('loyalty_dependents');
        Schema::dropIfExists('loyalty_subscriptions');
        Schema::dropIfExists('loyalty_plans');
    }
};
