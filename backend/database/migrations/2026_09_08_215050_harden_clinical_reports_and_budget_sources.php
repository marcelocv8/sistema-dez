<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->timestampTz('purged_at')->nullable();
        });
        Schema::table('report_events', function (Blueprint $table) {
            $table->unsignedBigInteger('by_user_id')->nullable()->change();
        });
        Schema::table('budgets', function (Blueprint $table) {
            $table->foreignUuid('unit_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::table('budget_items', function (Blueprint $table) {
            $table->foreignUuid('procedure_price_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('budget_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('procedure_price_id');
        });
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_id');
        });
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('purged_at');
        });
    }
};
