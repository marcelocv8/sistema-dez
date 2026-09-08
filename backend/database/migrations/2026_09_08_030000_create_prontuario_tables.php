<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('patient_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('professional_id')->nullable()->constrained('professionals')->nullOnDelete();
            $table->string('kind', 30)->default('EVOLUCAO');
            $table->text('content_html');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('retention_until')->nullable();
            $table->timestampsTz();
            $table->index(['patient_id', 'created_at']);
            $table->index('appointment_id');
        });

        Schema::create('patient_alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20)->default('ALERGIA');
            $table->string('title', 200);
            $table->string('detail', 500)->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('deactivated_at')->nullable();
            $table->string('deactivation_reason', 500)->nullable();
            $table->timestampsTz();
            $table->index(['patient_id', 'active']);
        });

        Schema::create('prontuario_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('subject_id')->nullable();
            $table->string('event', 30);
            $table->foreignId('by_user_id')->constrained('users')->restrictOnDelete();
            $table->jsonb('payload')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->index(['patient_id', 'created_at']);
        });

        Schema::create('portal_accesses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained()->cascadeOnDelete();
            $table->string('login', 20)->unique();
            $table->string('password_hash');
            $table->string('token_hash', 128)->nullable()->index();
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoked_reason', 500)->nullable();
            $table->unsignedInteger('access_count')->default(0);
            $table->timestampTz('last_accessed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['patient_id', 'expires_at']);
        });

        Schema::create('ai_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('model', 60);
            $table->text('prompt_summary');
            $table->text('response_text')->nullable();
            $table->string('status', 20)->default('OK');
            $table->string('error_message', 500)->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->index(['patient_id', 'created_at']);
        });

        Schema::table('reports', function (Blueprint $table): void {
            $table->foreignUuid('portal_access_id')->nullable()->constrained('portal_accesses')->nullOnDelete();
            $table->timestampTz('portal_published_at')->nullable();
            $table->foreignId('portal_published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('portal_unpublished_at')->nullable();
            $table->index(['portal_published_at']);
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropColumn(['portal_access_id', 'portal_published_at', 'portal_published_by', 'portal_unpublished_at']);
        });
        Schema::dropIfExists('ai_requests');
        Schema::dropIfExists('portal_accesses');
        Schema::dropIfExists('prontuario_events');
        Schema::dropIfExists('patient_alerts');
        Schema::dropIfExists('clinical_notes');
    }
};
