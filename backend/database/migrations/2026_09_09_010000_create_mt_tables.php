<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mt_risks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 200);
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->timestampsTz();
            $table->index(['active', 'name']);
        });

        Schema::create('mt_sectors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agreement_id')->constrained()->restrictOnDelete();
            $table->string('name', 200);
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->timestampsTz();
            $table->unique(['agreement_id', 'name']);
        });

        Schema::create('mt_functions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agreement_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('sector_id')->nullable()->constrained('mt_sectors')->nullOnDelete();
            $table->foreignUuid('risk_id')->nullable()->constrained('mt_risks')->nullOnDelete();
            $table->string('name', 200);
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->timestampsTz();
            $table->index(['agreement_id', 'name']);
        });

        Schema::create('mt_asos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('agreement_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('sector_id')->nullable()->constrained('mt_sectors')->nullOnDelete();
            $table->foreignUuid('function_id')->nullable()->constrained('mt_functions')->nullOnDelete();
            $table->string('type', 30);
            $table->string('status', 20)->default('PENDENTE');
            $table->date('realized_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->foreignUuid('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('report_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('issued_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('legacy_situacao', 200)->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['agreement_id', 'status']);
            $table->index(['expires_at', 'status']);
            $table->index('patient_id');
        });

        Schema::create('mt_aso_risks', function (Blueprint $table): void {
            $table->foreignUuid('mt_aso_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('risk_id')->constrained('mt_risks')->restrictOnDelete();
            $table->primary(['mt_aso_id', 'risk_id']);
        });

        Schema::create('mt_aso_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('mt_aso_id')->constrained()->cascadeOnDelete();
            $table->string('event', 30);
            $table->foreignId('by_user_id')->constrained('users')->restrictOnDelete();
            $table->jsonb('payload')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->index(['mt_aso_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mt_aso_events');
        Schema::dropIfExists('mt_aso_risks');
        Schema::dropIfExists('mt_asos');
        Schema::dropIfExists('mt_functions');
        Schema::dropIfExists('mt_sectors');
        Schema::dropIfExists('mt_risks');
    }
};
