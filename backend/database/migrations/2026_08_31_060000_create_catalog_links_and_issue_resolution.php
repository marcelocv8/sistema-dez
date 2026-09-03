<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('entity_key', 64)->nullable()->after('entity_id');
            $table->index(['entity_type', 'entity_key']);
        });

        Schema::create('catalog_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('unit_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('procedure_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('professional_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('agreement_id')->constrained()->restrictOnDelete();
            $table->boolean('active')->default(true)->index();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(
                ['unit_id', 'procedure_id', 'professional_id', 'agreement_id'],
                'catalog_links_unique_relationship',
            );
            $table->index(['professional_id', 'active'], 'catalog_links_professional_index');
            $table->index(['procedure_id', 'agreement_id', 'active'], 'catalog_links_procedure_index');
        });

        Schema::table('stg_import_issues', function (Blueprint $table) {
            $table->string('resolution_status')->default('OPEN')->index();
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->index(['resolution_status', 'entity_type'], 'stg_issues_resolution_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::table('stg_import_issues', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropIndex('stg_issues_resolution_lookup_index');
            $table->dropColumn(['resolution_status', 'resolution_note', 'resolved_at']);
        });

        Schema::dropIfExists('catalog_links');

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['entity_type', 'entity_key']);
            $table->dropColumn('entity_key');
        });
    }
};
