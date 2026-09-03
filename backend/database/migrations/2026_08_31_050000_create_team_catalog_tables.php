<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('RECEPTION')->index();
            $table->boolean('active')->default(true)->index();
        });

        Schema::table('agreements', function (Blueprint $table) {
            $table->string('classification_status')->nullable()->index();
            $table->string('search_name')->nullable()->index();
        });

        Schema::table('procedures', function (Blueprint $table) {
            $table->string('classification_status')->nullable()->index();
            $table->string('search_name')->nullable()->index();
        });

        Schema::table('professionals', function (Blueprint $table) {
            $table->string('classification_status')->nullable()->index();
            $table->string('search_name')->nullable()->index();
        });

        Schema::create('stg_import_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('checksum', 64)->index();
            $table->string('status');
            $table->json('summary');
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('stg_import_issues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('import_run_id')->constrained('stg_import_runs')->cascadeOnDelete();
            $table->string('code')->index();
            $table->string('entity_type');
            $table->string('legacy_id')->nullable();
            $table->string('source_file');
            $table->json('payload');
            $table->timestampsTz();
            $table->index(['import_run_id', 'entity_type']);
        });

        Schema::create('procedure_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('procedure_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('agreement_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('nature');
            $table->decimal('amount', 10, 2);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('status')->default('VALID');
            $table->string('source_file');
            $table->string('source_key', 64)->unique();
            $table->string('source_checksum', 64);
            $table->timestampsTz();
            $table->index(
                ['agreement_id', 'status', 'nature', 'valid_from', 'valid_until'],
                'procedure_prices_current_lookup_index',
            );
            $table->index(['procedure_id', 'agreement_id', 'unit_id'], 'procedure_prices_entity_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procedure_prices');
        Schema::dropIfExists('stg_import_issues');
        Schema::dropIfExists('stg_import_runs');

        Schema::table('professionals', function (Blueprint $table) {
            $table->dropColumn(['classification_status', 'search_name']);
        });

        Schema::table('procedures', function (Blueprint $table) {
            $table->dropColumn(['classification_status', 'search_name']);
        });

        Schema::table('agreements', function (Blueprint $table) {
            $table->dropColumn(['classification_status', 'search_name']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'active']);
        });
    }
};
