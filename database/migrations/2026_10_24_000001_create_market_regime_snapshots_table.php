<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The daily rule-based Market Regime, one row per session of the main
 * Universe (docs/specs/market-regime.md, Domain Rule 6). Derived data written
 * only by `market-regime:compute`; `components` holds every component's
 * status, score, value, date and staleness (no text).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_regime_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('regime', 16);
            $table->smallInteger('score')->nullable();
            $table->unsignedTinyInteger('available');
            $table->json('components');
            $table->unsignedSmallInteger('rules_version');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_regime_snapshots');
    }
};
