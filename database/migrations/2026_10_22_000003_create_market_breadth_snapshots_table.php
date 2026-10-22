<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily market Breadth of the main Universe, one row per Universe and session
 * (docs/specs/market-context-sectors.md, Domain Rule 6). Derived data written
 * only by `market-context:compute`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_breadth_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('universe_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('members');
            $table->decimal('pct_above_sma50', 7, 4)->nullable();
            $table->decimal('pct_above_sma200', 7, 4)->nullable();
            $table->unsignedInteger('new_highs')->nullable();
            $table->unsignedInteger('new_lows')->nullable();
            $table->unsignedInteger('advancers');
            $table->unsignedInteger('decliners');
            $table->string('spy_trend', 10)->nullable();
            $table->timestamps();

            $table->unique(['universe_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_breadth_snapshots');
    }
};
