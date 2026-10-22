<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instrument kind (docs/specs/market-context-sectors.md, Domain Rule 1):
 * `stock` (default, every existing row) or `etf` (the Market Context sector
 * ETFs and SPY, which never reach the stock features).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instruments', function (Blueprint $table) {
            $table->string('kind', 16)->default('stock')->index();
        });
    }

    public function down(): void
    {
        Schema::table('instruments', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });
    }
};
