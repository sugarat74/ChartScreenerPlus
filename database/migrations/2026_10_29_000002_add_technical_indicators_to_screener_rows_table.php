<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Technical indicator columns of each Screener row (screener-technical-filters-api,
 * technical-indicators-expansion D4): ADX and Stochastic %K copied from the
 * latest Indicator Snapshot, and %B, Bollinger bandwidth (percent) and ATR as a
 * percent of the close derived from it. All nullable (warm-up, zero-width band,
 * zero close) and filled only by `ScreenerRowBuilder` (`screener:refresh`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screener_rows', function (Blueprint $table): void {
            $table->decimal('adx', 12, 4)->nullable()->index();
            $table->double('bb_pctb')->nullable()->index();
            $table->double('bb_width')->nullable()->index();
            $table->double('atr_pct')->nullable()->index();
            $table->decimal('stoch_k', 12, 4)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('screener_rows', function (Blueprint $table): void {
            $table->dropIndex(['adx']);
            $table->dropIndex(['bb_pctb']);
            $table->dropIndex(['bb_width']);
            $table->dropIndex(['atr_pct']);
            $table->dropIndex(['stoch_k']);
            $table->dropColumn(['adx', 'bb_pctb', 'bb_width', 'atr_pct', 'stoch_k']);
        });
    }
};
