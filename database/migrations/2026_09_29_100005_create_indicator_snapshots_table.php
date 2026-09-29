<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('indicator_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instrument_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('sma20', 12, 4)->nullable();
            $table->decimal('sma50', 12, 4)->nullable();
            $table->decimal('sma200', 12, 4)->nullable();
            $table->decimal('ema21', 12, 4)->nullable();
            $table->decimal('ema55', 12, 4)->nullable();
            $table->decimal('rsi14', 12, 4)->nullable();
            $table->decimal('adx', 12, 4)->nullable();
            $table->decimal('macd', 12, 4)->nullable();
            $table->decimal('macd_signal', 12, 4)->nullable();
            $table->decimal('macd_hist', 12, 4)->nullable();
            $table->decimal('bb_upper', 12, 4)->nullable();
            $table->decimal('bb_middle', 12, 4)->nullable();
            $table->decimal('bb_lower', 12, 4)->nullable();
            $table->decimal('rvol', 8, 4)->nullable();
            $table->timestamps();

            $table->unique(['instrument_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('indicator_snapshots');
    }
};
