<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_offerings', function (Blueprint $table): void {
            $table->boolean('online_paused')->default(false)->after('online_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('service_offerings', function (Blueprint $table): void {
            $table->dropColumn('online_paused');
        });
    }
};
