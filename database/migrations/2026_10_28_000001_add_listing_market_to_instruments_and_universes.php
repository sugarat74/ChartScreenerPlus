<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * universe-registry: Listing Market and currency per Instrument and Universe,
 * the Screener universe registry flags, and the market/currency copied into
 * the precomputed Screener rows. Additive with defaults, so every existing
 * row (Market Context ETFs included) stays US/USD. Portable between SQLite and
 * PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instruments', function (Blueprint $table): void {
            $table->string('market', 8)->default('US');
            $table->char('currency', 3)->default('USD');
        });

        Schema::table('universes', function (Blueprint $table): void {
            $table->string('market', 8)->default('US');
            $table->char('currency', 3)->default('USD');
            $table->boolean('is_screener')->default(false);
            $table->smallInteger('position')->default(0);
        });

        Schema::table('screener_rows', function (Blueprint $table): void {
            $table->string('market', 8)->default('US');
            $table->char('currency', 3)->default('USD');
        });

        // The S&P 500 is the one Screener universe so far (market-context is not).
        DB::table('universes')->where('slug', 'sp500')->update(['is_screener' => true, 'position' => 0]);
    }

    public function down(): void
    {
        Schema::table('screener_rows', function (Blueprint $table): void {
            $table->dropColumn(['market', 'currency']);
        });

        Schema::table('universes', function (Blueprint $table): void {
            $table->dropColumn(['market', 'currency', 'is_screener', 'position']);
        });

        Schema::table('instruments', function (Blueprint $table): void {
            $table->dropColumn(['market', 'currency']);
        });
    }
};
