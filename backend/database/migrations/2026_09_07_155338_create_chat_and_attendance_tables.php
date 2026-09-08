<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('service_date');
            $table->timestampTz('logged_in_at');
            $table->timestampTz('created_at');
            $table->index(['user_id', 'service_date']);
            $table->index('service_date');
        });

        Schema::create('app_settings', function (Blueprint $table): void {
            $table->string('key', 100)->primary();
            $table->string('value', 255);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at');
        });

        Schema::create('chat_conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type', 20)->default('DIRECT');
            $table->string('title', 120)->nullable();
            $table->foreignUuid('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index('type');
        });

        Schema::create('chat_participants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('chat_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestampTz('last_read_at', 6)->nullable();
            $table->timestampsTz();
            $table->unique(['chat_conversation_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('chat_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('chat_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('body');
            $table->foreignUuid('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('created_at', 6);
            $table->index(['chat_conversation_id', 'created_at']);
            $table->index('patient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_participants');
        Schema::dropIfExists('chat_conversations');
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('attendance_events');
    }
};
