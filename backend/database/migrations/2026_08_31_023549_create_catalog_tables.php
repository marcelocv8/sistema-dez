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
        Schema::create('units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('legacy_id')->nullable()->unique();
            $table->string('name');
            $table->string('timezone')->default('America/Fortaleza');
            $table->string('cnpj', 14)->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('agreements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('legacy_id')->nullable()->unique();
            $table->string('name');
            $table->string('type');
            $table->boolean('is_default')->default(false);
            $table->boolean('online_enabled')->default(true);
            $table->boolean('active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('procedures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('legacy_id')->nullable()->unique();
            $table->string('name');
            $table->string('type');
            $table->string('group_name')->nullable();
            $table->string('tuss_code')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->boolean('active')->default(true);
            $table->boolean('legacy')->default(false);
            $table->boolean('online_enabled')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['type', 'active', 'online_enabled']);
        });

        Schema::create('professionals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('legacy_id')->nullable()->unique();
            $table->string('name');
            $table->string('council_acronym')->nullable();
            $table->string('council_number')->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('online_enabled')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('patients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('legacy_id')->nullable()->unique();
            $table->string('name');
            $table->string('cpf_hash', 64)->nullable()->unique();
            $table->string('cpf_last_four', 4)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->foreignUuid('default_agreement_id')->nullable()->constrained('agreements')->nullOnDelete();
            $table->timestampTz('privacy_consent_at')->nullable();
            $table->string('privacy_consent_source')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('service_offerings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('unit_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('procedure_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('professional_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('agreement_id')->constrained()->cascadeOnDelete();
            $table->string('booking_mode');
            $table->string('modality');
            $table->decimal('price', 10, 2);
            $table->unsignedSmallInteger('duration_override_minutes')->nullable();
            $table->unsignedSmallInteger('daily_capacity')->nullable();
            $table->unsignedSmallInteger('arrival_block_minutes')->default(60);
            $table->unsignedSmallInteger('arrival_block_capacity')->default(3);
            $table->boolean('online_enabled')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletesTz();
            $table->index(['unit_id', 'agreement_id', 'modality', 'active'], 'offerings_public_lookup_index');
            $table->index(['procedure_id', 'professional_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_offerings');
        Schema::dropIfExists('patients');
        Schema::dropIfExists('professionals');
        Schema::dropIfExists('procedures');
        Schema::dropIfExists('agreements');
        Schema::dropIfExists('units');
    }
};
