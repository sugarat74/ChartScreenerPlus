<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared AI Company Cards (ai-company-card): one validated card per
 * Instrument, locale and schema version, with no user identity. Portable
 * SQLite/PostgreSQL: `content` is a plain `json` column read and written only
 * through Eloquent's `array` cast (no JSON operators in queries).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_company_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instrument_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 8);
            $table->string('schema_version', 40);
            $table->string('prompt_version', 40);
            $table->string('model', 120);
            $table->string('context_mode', 24);
            $table->date('context_as_of_date')->nullable();
            $table->json('content');
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['instrument_id', 'locale', 'schema_version'], 'ai_company_cards_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_company_cards');
    }
};
