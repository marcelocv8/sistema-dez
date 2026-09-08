<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('legacy_type', 10);
            $table->unsignedBigInteger('legacy_id');
            $table->string('legacy_medico', 200)->nullable();
            $table->string('legacy_procedure', 200)->nullable();
            $table->string('nome', 200);
            $table->text('content_html');
            $table->foreignUuid('doctor_id')->nullable()->constrained('professionals')->nullOnDelete();
            $table->foreignUuid('procedure_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestampTz('imported_at')->nullable();
            $table->timestampsTz();
            $table->unique(['legacy_type', 'legacy_id']);
            $table->index(['procedure_id', 'active']);
            $table->index('doctor_id');
        });

        Schema::create('reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('appointment_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('patient_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('procedure_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('doctor_id')->nullable()->constrained('professionals')->nullOnDelete();
            $table->foreignUuid('template_id')->nullable()->constrained('report_templates')->nullOnDelete();
            $table->text('content_html');
            $table->text('content_backup')->nullable();
            $table->string('status', 20)->default('RASCUNHO');
            $table->foreignId('typed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('finalized_at')->nullable();
            $table->date('retention_until')->nullable();
            $table->text('adendo')->nullable();
            $table->foreignId('signed_manual_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('signed_manual_at')->nullable();
            $table->unsignedInteger('print_count')->default(0);
            $table->foreignId('last_print_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('last_printed_at')->nullable();
            $table->timestampsTz();
            $table->index(['patient_id', 'created_at']);
            $table->index('appointment_id');
            $table->index('status');
        });

        Schema::create('report_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_id')->constrained()->cascadeOnDelete();
            $table->string('event', 30);
            $table->foreignId('by_user_id')->constrained('users')->restrictOnDelete();
            $table->jsonb('payload')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->index(['report_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_events');
        Schema::dropIfExists('reports');
        Schema::dropIfExists('report_templates');
    }
};
