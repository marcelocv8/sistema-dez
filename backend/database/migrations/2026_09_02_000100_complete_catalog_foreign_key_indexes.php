<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_links', fn (Blueprint $table) => $table->index('agreement_id'));
        Schema::table('service_offerings', function (Blueprint $table): void {
            $table->index('agreement_id');
            $table->index('professional_id');
        });
        Schema::table('procedure_prices', fn (Blueprint $table) => $table->index('unit_id'));
        Schema::table('patients', fn (Blueprint $table) => $table->index('default_agreement_id'));
    }

    public function down(): void
    {
        Schema::table('patients', fn (Blueprint $table) => $table->dropIndex(['default_agreement_id']));
        Schema::table('procedure_prices', fn (Blueprint $table) => $table->dropIndex(['unit_id']));
        Schema::table('service_offerings', function (Blueprint $table): void {
            $table->dropIndex(['professional_id']);
            $table->dropIndex(['agreement_id']);
        });
        Schema::table('catalog_links', fn (Blueprint $table) => $table->dropIndex(['agreement_id']));
    }
};
