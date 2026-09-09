<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // T2: mensagens enviadas pelo DEZ através do fork AppChat (§6.12.1).
        Schema::create('whatsapp_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->string('event_key', 40); // booking | d2_confirmation
            $table->string('to_phone', 20);
            $table->text('body');
            $table->string('status', 20)->default('QUEUED'); // QUEUED|SENT|DELIVERED|READ|FAILED
            $table->string('provider_message_key')->nullable();
            $table->string('idempotency_key', 128)->unique();
            $table->text('error')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'event_key']);
            $table->index('appointment_id');
        });

        // Eventos brutos recebidos do AppChat (append-only; replay/auditoria).
        Schema::create('whatsapp_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider_event_id', 191)->unique();
            $table->string('type', 40); // mensagem_recebida | mensagem_status
            $table->string('phone', 20)->nullable();
            $table->string('message_key')->nullable();
            $table->jsonb('payload');
            $table->string('result', 40)->nullable(); // CONFIRMED|DECLINED|IGNORED|STATUS
            $table->timestampTz('received_at', 6)->useCurrent();
        });

        // T3: ciclo de confirmação D-2 por atendimento (um por dia de disparo).
        Schema::create('appointment_confirmations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('appointment_id')->constrained('appointments')->cascadeOnDelete();
            $table->string('status', 20)->default('PENDING'); // PENDING|CONFIRMED|DECLINED|NO_RESPONSE
            $table->date('requested_on');
            $table->timestampTz('requested_at')->nullable();
            $table->timestampTz('responded_at')->nullable();
            $table->foreignUuid('outbox_id')->nullable()->constrained('whatsapp_outbox')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['appointment_id', 'requested_on']);
            $table->index(['status', 'requested_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_confirmations');
        Schema::dropIfExists('whatsapp_events');
        Schema::dropIfExists('whatsapp_outbox');
    }
};
