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
        Schema::table('service_offerings', function (Blueprint $table): void {
            $table->date('published_until')->nullable();
            $table->timestampTz('published_at')->nullable();
        });
        Schema::table('agreements', fn (Blueprint $table) => $table->boolean('requires_authorization')->default(false));
        Schema::table('appointments', fn (Blueprint $table) => $table->timestampTz('management_expires_at')->nullable());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', fn (Blueprint $table) => $table->dropColumn('management_expires_at'));
        Schema::table('agreements', fn (Blueprint $table) => $table->dropColumn('requires_authorization'));
        Schema::table('service_offerings', fn (Blueprint $table) => $table->dropColumn(['published_until', 'published_at']));
    }
};
