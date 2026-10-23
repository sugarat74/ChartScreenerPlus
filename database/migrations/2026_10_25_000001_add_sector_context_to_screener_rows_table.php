<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sector context of each Screener row (docs/specs/market-context-patterns.md,
 * C4): the trend and relative-strength rank of the stock's sector ETF on the
 * latest Sector Snapshot date, or null (no sector, an unmapped one, or a
 * snapshot more than 7 days older than the row's bar). Filled only by
 * `ScreenerRowBuilder`; never part of the Candidate JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screener_rows', function (Blueprint $table) {
            $table->string('sector_trend', 10)->nullable()->index();
            $table->unsignedTinyInteger('sector_rs_rank')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('screener_rows', function (Blueprint $table) {
            $table->dropIndex(['sector_trend']);
            $table->dropIndex(['sector_rs_rank']);
            $table->dropColumn(['sector_trend', 'sector_rs_rank']);
        });
    }
};
