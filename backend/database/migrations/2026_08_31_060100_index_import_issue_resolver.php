<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stg_import_issues', function (Blueprint $table) {
            $table->index('resolved_by');
        });
    }

    public function down(): void
    {
        Schema::table('stg_import_issues', function (Blueprint $table) {
            $table->dropIndex(['resolved_by']);
        });
    }
};
