<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per natural-language Screener request (cost control, daily caps
 * and abuse prevention; ai-screener-natural-language). It stores metadata
 * only: never the query text, the prompt or the answer. Pruned after
 * `config('ai_screener.log_retention_days')`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_screener_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('locale', 8);
            $table->unsignedSmallInteger('query_length');
            // ok | cached | invalid | unavailable | limited
            $table->string('outcome', 16);
            $table->unsignedSmallInteger('unsupported_count')->default(0);
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('model', 120)->nullable();
            $table->string('schema_version', 40);
            $table->string('prompt_version', 40);
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_screener_queries');
    }
};
