<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('token_hash', 64)->unique();
            $table->jsonb('scopes');
            $table->boolean('active')->default(true);
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['active', 'name']);
        });

        Schema::create('agent_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_token_id')->constrained('agent_tokens')->cascadeOnDelete();
            $table->string('endpoint', 120);
            $table->string('method', 10);
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->index(['agent_token_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_requests');
        Schema::dropIfExists('agent_tokens');
    }
};
