<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('schedule_windows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_offering_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedTinyInteger('weekday');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->unsignedSmallInteger('slot_minutes')->nullable();
            $table->unsignedSmallInteger('daily_capacity_override')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['service_offering_id', 'weekday', 'active'], 'windows_lookup_index');
        });

        Schema::create('schedule_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_offering_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->string('reason');
            $table->boolean('blocked')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['service_offering_id', 'date']);
        });

        Schema::create('schedule_occurrences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_offering_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('schedule_window_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->unsignedSmallInteger('capacity');
            $table->string('status')->default('OPEN');
            $table->timestampsTz();
            $table->unique(['service_offering_id', 'date', 'starts_at'], 'occurrences_unique_slot');
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('service_offering_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('scheduled_at');
            $table->timestampTz('recommended_arrival_at')->nullable();
            $table->string('status')->default('CONFIRMED');
            $table->string('kind')->default('NEW');
            $table->string('origin')->default('ONLINE');
            $table->decimal('price_snapshot', 10, 2);
            $table->uuid('idempotency_key')->unique();
            $table->string('management_token_hash', 64)->nullable()->unique();
            $table->text('return_override_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['service_offering_id', 'scheduled_at', 'status'], 'appointments_availability_index');
            $table->index(['patient_id', 'status']);
        });

        Schema::create('queue_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('unit_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('service_offering_id')->constrained()->cascadeOnDelete();
            $table->date('service_date');
            $table->timestampTz('checked_in_at');
            $table->unsignedBigInteger('original_sequence');
            $table->string('priority_code')->nullable();
            $table->unsignedSmallInteger('priority_rank')->default(100);
            $table->text('priority_reason')->nullable();
            $table->foreignId('reordered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('WAITING');
            $table->timestampsTz();
            $table->index(['unit_id', 'service_date', 'status', 'priority_rank'], 'queue_order_index');
            $table->index(['service_offering_id', 'service_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('queue_entries');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('schedule_occurrences');
        Schema::dropIfExists('schedule_exceptions');
        Schema::dropIfExists('schedule_windows');
    }
};
