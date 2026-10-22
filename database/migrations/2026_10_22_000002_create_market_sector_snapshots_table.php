<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily sector snapshot, one row per sector ETF and session
 * (docs/specs/market-context-sectors.md, Domain Rule 5). Derived data written
 * only by `market-context:compute`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_sector_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('instrument_id')->constrained()->cascadeOnDelete();
            $table->string('sector');
            $table->decimal('ret_5', 10, 4)->nullable();
            $table->decimal('ret_20', 10, 4)->nullable();
            $table->decimal('ret_60', 10, 4)->nullable();
            $table->decimal('rs_20', 10, 4)->nullable();
            $table->decimal('rs_60', 10, 4)->nullable();
            $table->unsignedTinyInteger('rs_rank')->nullable();
            $table->string('trend', 10)->nullable();
            $table->decimal('breadth_200', 7, 4)->nullable();
            $table->timestamps();

            $table->unique(['date', 'instrument_id']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_sector_snapshots');
    }
};
