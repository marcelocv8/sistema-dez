<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table): void {
            $table->boolean('operational_enabled')->default(false)->index();
        });

        DB::table('units')->where('legacy_id', '1')->update(['operational_enabled' => true]);

        Schema::table('service_offerings', function (Blueprint $table): void {
            $table->foreignUuid('catalog_link_id')->nullable()->after('id')->constrained('catalog_links')->restrictOnDelete();
            $table->foreignUuid('procedure_price_id')->nullable()->after('agreement_id')->constrained('procedure_prices')->restrictOnDelete();
            $table->index('catalog_link_id');
            $table->index('procedure_price_id');
        });
        DB::statement('ALTER TABLE service_offerings ALTER COLUMN online_enabled SET DEFAULT false');

        Schema::table('appointments', function (Blueprint $table): void {
            $table->foreignUuid('schedule_occurrence_id')->nullable()->after('service_offering_id')->constrained('schedule_occurrences')->nullOnDelete();
            $table->text('cancellation_reason')->nullable()->after('return_override_reason');
            $table->index('schedule_occurrence_id');
            $table->index('created_by');
            $table->index('scheduled_at', 'appointments_scheduled_at_index');
        });

        Schema::table('schedule_exceptions', fn (Blueprint $table) => $table->index('created_by'));
        Schema::table('schedule_occurrences', function (Blueprint $table): void {
            $table->index('schedule_window_id');
            $table->index(['date', 'status'], 'occurrences_date_status_index');
        });
        Schema::table('queue_entries', function (Blueprint $table): void {
            $table->index('appointment_id');
            $table->index('reordered_by');
        });
    }

    public function down(): void
    {
        Schema::table('queue_entries', function (Blueprint $table): void {
            $table->dropIndex(['appointment_id']);
            $table->dropIndex(['reordered_by']);
        });
        Schema::table('schedule_occurrences', function (Blueprint $table): void {
            $table->dropIndex(['schedule_window_id']);
            $table->dropIndex('occurrences_date_status_index');
        });
        Schema::table('schedule_exceptions', fn (Blueprint $table) => $table->dropIndex(['created_by']));
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropIndex(['created_by']);
            $table->dropIndex('appointments_scheduled_at_index');
            $table->dropConstrainedForeignId('schedule_occurrence_id');
            $table->dropColumn('cancellation_reason');
        });
        Schema::table('service_offerings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('procedure_price_id');
            $table->dropConstrainedForeignId('catalog_link_id');
        });
        Schema::table('units', fn (Blueprint $table) => $table->dropColumn('operational_enabled'));
    }
};
