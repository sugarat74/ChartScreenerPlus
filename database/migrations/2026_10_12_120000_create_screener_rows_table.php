<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Precomputed Candidate rows (docs/specs/screener-precomputed.md): one row
     * per Instrument with a latest Daily Bar and Indicator Snapshot, rebuilt by
     * `screener:refresh`, so the Screener filters, ranks and pages in SQL.
     */
    public function up(): void
    {
        Schema::create('screener_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instrument_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('ticker');
            $table->string('company');
            $table->string('sector')->nullable();
            $table->string('exchange')->nullable();
            $table->boolean('active');
            $table->date('date');
            $table->decimal('close', 12, 4);
            $table->decimal('previous_close', 12, 4)->nullable();
            $table->double('change_percent')->nullable();
            $table->decimal('rvol', 8, 4)->nullable();
            $table->decimal('rsi14', 12, 4)->nullable();
            $table->decimal('sma50', 12, 4)->nullable();
            $table->decimal('sma200', 12, 4)->nullable();
            $table->boolean('price_above_sma200');
            $table->string('ma_cross', 8)->nullable();
            $table->unsignedSmallInteger('signal_count');
            $table->json('signals');
            $table->json('patterns');
            $table->timestamps();

            $table->index('rvol');
            $table->index('rsi14');
            $table->index('change_percent');
            $table->index('signal_count');
            $table->index('ticker');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('screener_rows');
    }
};
