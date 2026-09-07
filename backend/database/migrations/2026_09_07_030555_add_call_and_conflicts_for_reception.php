<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_entries', function (Blueprint $table): void {
            $table->timestampTz('called_at')->nullable();
            $table->foreignId('called_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('schedule_conflicts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_offering_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('schedule_window_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('schedule_exception_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 500);
            $table->json('affected');
            $table->string('status', 20)->default('OPEN');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestampsTz();
            $table->index(['service_offering_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_conflicts');
        Schema::table('queue_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('called_by');
            $table->dropColumn('called_at');
        });
    }
};
