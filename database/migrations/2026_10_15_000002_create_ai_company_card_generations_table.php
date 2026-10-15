<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per attempted provider call for a Company Card (cost control,
 * daily caps and abuse prevention). It stores no prompt or response text and
 * is pruned after `config('ai_card.log_retention_days')`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_company_card_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instrument_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('locale', 8);
            $table->string('model', 120);
            $table->string('schema_version', 40);
            $table->string('prompt_version', 40);
            $table->string('response_format', 16);
            $table->string('status', 24);
            $table->string('error_code', 40)->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_company_card_generations');
    }
};
