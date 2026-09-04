<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('professionals', function (Blueprint $table): void {
            $table->string('commission_type', 10)->default('PERCENT')->after('council_number');
            $table->decimal('commission_value', 10, 2)->default(0)->after('commission_type');
            $table->foreignId('user_id')->nullable()->after('commission_value')->constrained('users')->nullOnDelete();
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('professionals', function (Blueprint $table): void {
            $table->dropUnique(['user_id']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['commission_type', 'commission_value']);
        });
    }
};
