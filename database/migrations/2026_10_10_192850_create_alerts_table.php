<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Registered User's Alert rules (docs/specs/alerts-engine.md).
     *
     * `last_state` holds what the previous evaluation saw (candidate tickers,
     * or ticker => active signal types), so the next evaluation notifies only
     * additions; `last_evaluated_as_of` makes evaluation once per as-of date.
     */
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->foreignId('saved_screener_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('signal_types')->nullable();
            $table->boolean('active')->default(true);
            $table->date('last_evaluated_as_of')->nullable();
            $table->json('last_state')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'kind']);
            $table->unique(['user_id', 'saved_screener_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
